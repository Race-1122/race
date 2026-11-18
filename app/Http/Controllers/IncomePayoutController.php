<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Services\Blockchain\RaceCoinDirectMintService;
use App\Services\Income\RaceCoinService;
use App\Services\Income\WalletBalanceService;
use App\Services\Income\WithdrawalService;
use App\Support\RewardPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dedicated member page: Virtual Income (USDT) → RACE minted to the member wallet via RaceCoin.mint.
 * Uses the same WithdrawalService rules (fees, locks, rate limit); no admin approval.
 */
class IncomePayoutController extends Controller
{
    public function index(
        Request $request,
        WalletBalanceService $wallets,
        WithdrawalService $withdrawalService,
        RaceCoinDirectMintService $mint,
    ): Response {
        $user = $request->user();

        $racePrice = null;
        try {
            $racePrice = app(RaceCoinService::class)->formattedPriceUsd(8);
        } catch (\Throwable $e) {
            report($e);
        }

        $payouts = Withdrawal::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(30)
            ->get([
                'id',
                'amount_usd',
                'net_usd',
                'race_amount',
                'race_tx_hash',
                'status',
                'created_at',
            ])
            ->map(static fn (Withdrawal $w) => [
                'id' => $w->id,
                'amount_usd' => $w->amount_usd,
                'net_usd' => $w->net_usd,
                'race_amount' => $w->race_amount,
                'race_tx_hash' => $w->race_tx_hash,
                'status' => $w->status,
                'status_label' => Withdrawal::statusLabels()[$w->status] ?? ucfirst((string) $w->status),
                'created_at' => $w->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return Inertia::render('IncomePayout', [
            'balance_usd' => $wallets->virtualIncomeBalance($user),
            'wallet_address' => $user->wallet_address,
            'withdrawal_fee' => RewardPlan::walletWithdrawalFeeRulesForUi(),
            'payout_enabled' => $mint->enabled(),
            'race_price_usd' => $racePrice,
            'withdrawals_disabled' => ! $user->canRequestWithdrawal(),
            'withdrawal_lock_message' => $user->withdrawalLockMessage(),
            'rate_limit' => $withdrawalService->rateLimitStatus($user),
            'payouts' => $payouts,
        ]);
    }

    public function store(
        Request $request,
        WithdrawalService $withdrawals,
        RaceCoinDirectMintService $mint,
    ): RedirectResponse {
        if (! $mint->enabled()) {
            return back()->with('error', __('RACE payout is not enabled yet. Please try again later.'));
        }

        $user = $request->user();
        $storedAddress = trim((string) ($user->wallet_address ?? ''));

        $validated = $request->validate([
            'amount_usd' => ['required', 'numeric', 'min:1', 'max:999999.99'],
            'destination_address' => [$storedAddress !== '' ? 'nullable' : 'required', 'string', 'min:10', 'max:255'],
        ]);

        $destination = $storedAddress !== ''
            ? $storedAddress
            : (string) ($validated['destination_address'] ?? '');

        try {
            $withdrawal = $withdrawals->request(
                $user,
                (string) $validated['amount_usd'],
                $destination,
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('Payout could not be completed. Please try again.'));
        }

        if ($withdrawal->status === Withdrawal::STATUS_COMPLETED && filled($withdrawal->race_tx_hash)) {
            return back()->with(
                'status',
                __('RACE minted to your wallet. Net :net USDT → :race RACE (tx :tx).', [
                    'net' => number_format((float) $withdrawal->net_usd, 2),
                    'race' => number_format((float) $withdrawal->race_amount, 4),
                    'tx' => \Illuminate\Support\Str::limit((string) $withdrawal->race_tx_hash, 18, '…'),
                ]),
            );
        }

        return back()->with('status', __('Payout request submitted.'));
    }
}
