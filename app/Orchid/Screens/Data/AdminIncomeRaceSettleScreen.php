<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Data;

use App\Models\IncomeRaceSettlement;
use App\Models\User;
use App\Orchid\Concerns\RequiresPlatformDataPermission;
use App\Services\Income\IncomeRaceSettlementService;
use App\Services\Income\RaceCoinService;
use App\Services\Income\WalletBalanceService;
use App\Support\MemberCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Screen;
use Orchid\Screen\TD;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class AdminIncomeRaceSettleScreen extends Screen
{
    use RequiresPlatformDataPermission;

    public function query(WalletBalanceService $wallets, RaceCoinService $raceCoin): iterable
    {
        $price = $raceCoin->formattedPriceUsd(4);
        $multisig = (string) config('blockchain.contracts.multisig', '0x81BffBF2C2a258E662e9df0a0f6e34150b33F3df');
        $raceToken = (string) config('blockchain.contracts.race_token', '');

        $members = User::query()
            ->whereNotNull('wallet_address')
            ->where('wallet_address', '!=', '')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'name', 'email', 'member_number', 'wallet_address']);

        $eligible = [];
        foreach ($members as $user) {
            $bal = (float) $wallets->virtualIncomeBalance($user);
            if ($bal < 1) {
                continue;
            }
            $eligible[] = [
                'id' => $user->id,
                'member' => MemberCode::format($user->member_number).' · '.$user->email,
                'wallet' => $user->wallet_address,
                'usdt' => number_format($bal, 4, '.', ''),
                'race' => $raceCoin->coinsForUsdt($bal),
            ];
        }

        $settlements = IncomeRaceSettlement::query()
            ->with('user:id,name,email,member_number')
            ->latest('id')
            ->limit(50)
            ->get();

        $active = IncomeRaceSettlement::query()
            ->with('user:id,name,email,member_number')
            ->whereIn('status', [
                IncomeRaceSettlement::STATUS_QUEUED,
                IncomeRaceSettlement::STATUS_SUBMITTED,
            ])
            ->latest('id')
            ->first();

        return [
            'race_price_usd' => $price,
            'multisig_contract' => $multisig,
            'race_token' => $raceToken,
            'eligible_count' => count($eligible),
            'eligible_rows' => $eligible,
            'settlements' => $settlements,
            'queue_user_id' => null,
            'complete_settlement_id' => $active?->id,
            'tx_hash' => null,
            'multisig_tx_id' => $active?->multisig_tx_id,
            'active_settlement' => $active,
            'multisig_signers' => [
                '0xCc2B87f8E8AABc295e1119E186c3021bA6F32339',
                '0x8B2Ac41931d4869E7a53144BaF5C1fa052E8Df24',
                '0xDE64cA155dEb48B459360FB8E3c4e7167Bfeb150',
                '0xE9db9ea0fC494fB1Fc743DAfD5a0a436cA19EECA',
                '0x71DDFC006c21756DF7b07fB4357f6F6B33CE11f9',
            ],
        ];
    }

    public function name(): ?string
    {
        return __('Settle income as RACE');
    }

    public function description(): ?string
    {
        return __('Legacy path: Multisig RACE transfer (3-of-5) when Multisig already holds RACE. Preferred live path: enable RACE_INCOME_MINT_ON_WITHDRAW + Multisig setMinter(EOA) so user withdraw calls RaceCoin.mint (no new contract, no admin approve).');
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Queue settlement'))
                ->icon('bs.plus-circle')
                ->method('queue'),
            Button::make(__('Save Multisig tx id'))
                ->icon('bs.save')
                ->method('saveMultisigTxId'),
            Button::make(__('Mark completed + debit USDT'))
                ->icon('bs.check-circle')
                ->confirm(__('Confirm on-chain RACE transfer succeeded? This debits the member virtual USDT balance.'))
                ->method('complete'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::view('orchid.income-race-settle'),
            Layout::rows([
                Input::make('queue_user_id')
                    ->title(__('Queue — member user ID'))
                    ->type('number')
                    ->help(__('Member must have wallet_address + virtual income ≥ $1.')),
                Input::make('complete_settlement_id')
                    ->title(__('Settlement ID (for Multisig tx id / complete)'))
                    ->type('number'),
                Input::make('multisig_tx_id')
                    ->title(__('Multisig tx id (after Submit)'))
                    ->type('number'),
                Input::make('tx_hash')
                    ->title(__('BSC tx hash (after 3-of-5 execute)'))
                    ->help(__('Required to mark completed and debit virtual USDT.')),
            ]),
            Layout::table('settlements', [
                TD::make('id', 'ID')->width('70px'),
                TD::make('user', __('Member'))
                    ->render(fn (IncomeRaceSettlement $r) => e(
                        MemberCode::format($r->user?->member_number).' · '.($r->user?->email ?? '')
                    )),
                TD::make('usdt_amount', 'USDT'),
                TD::make('race_amount', 'RACE'),
                TD::make('status', __('Status')),
                TD::make('multisig_tx_id', 'MSIG #'),
                TD::make('wallet_address', __('Wallet'))
                    ->render(fn (IncomeRaceSettlement $r) => '<code class="user-select-all">'.e($r->wallet_address).'</code>'),
                TD::make('tx_hash', 'Tx')
                    ->render(fn (IncomeRaceSettlement $r) => $r->tx_hash
                        ? '<code class="user-select-all">'.e(substr($r->tx_hash, 0, 12)).'…</code>'
                        : '—'),
                TD::make(__('Actions'))
                    ->render(fn (IncomeRaceSettlement $r) => $r->status !== IncomeRaceSettlement::STATUS_COMPLETED
                        && $r->status !== IncomeRaceSettlement::STATUS_CANCELLED
                        ? Button::make(__('Cancel'))
                            ->method('cancel', ['id' => $r->id])
                            ->confirm(__('Cancel this settlement?'))
                        : '—'),
            ]),
        ];
    }

    public function queue(Request $request, IncomeRaceSettlementService $svc): RedirectResponse
    {
        $data = $request->validate([
            'queue_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        try {
            $user = User::query()->findOrFail((int) $data['queue_user_id']);
            $row = $svc->queue($user, $request->user());
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            Toast::error(__('Could not queue settlement.'));

            return redirect()->route('platform.data.income-race-settle');
        }

        Toast::success(__('Queued settlement #:id — :usdt USDT → :race RACE. Use Multisig buttons below.', [
            'id' => $row->id,
            'usdt' => $row->usdt_amount,
            'race' => $row->race_amount,
        ]));

        return redirect()->route('platform.data.income-race-settle');
    }

    public function saveMultisigTxId(Request $request, IncomeRaceSettlementService $svc): RedirectResponse
    {
        $data = $request->validate([
            'complete_settlement_id' => ['required', 'integer', 'exists:income_race_settlements,id'],
            'multisig_tx_id' => ['required', 'integer', 'min:0'],
        ]);

        $row = IncomeRaceSettlement::query()->findOrFail((int) $data['complete_settlement_id']);
        $svc->markSubmitted($row, (int) $data['multisig_tx_id']);

        Toast::info(__('Settlement #:id marked submitted (Multisig tx #:txid). After 3 confirmations, paste tx hash and Mark completed.', [
            'id' => $row->id,
            'txid' => $data['multisig_tx_id'],
        ]));

        return redirect()->route('platform.data.income-race-settle');
    }

    public function complete(Request $request, IncomeRaceSettlementService $svc): RedirectResponse
    {
        $data = $request->validate([
            'complete_settlement_id' => ['required', 'integer', 'exists:income_race_settlements,id'],
            'tx_hash' => ['required', 'string', 'min:66', 'max:80'],
        ]);

        $row = IncomeRaceSettlement::query()->findOrFail((int) $data['complete_settlement_id']);
        $svc->markCompleted($row, (string) $data['tx_hash']);

        Toast::success(__('Settlement #:id completed. Virtual USDT debited; RACE was sent on-chain.', [
            'id' => $row->id,
        ]));

        return redirect()->route('platform.data.income-race-settle');
    }

    public function cancel(Request $request, IncomeRaceSettlementService $svc): RedirectResponse
    {
        $id = (int) $request->input('id');
        $row = IncomeRaceSettlement::query()->findOrFail($id);
        $svc->cancel($row);
        Toast::info(__('Settlement #:id cancelled.', ['id' => $id]));

        return redirect()->route('platform.data.income-race-settle');
    }
}
