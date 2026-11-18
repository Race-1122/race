<?php

namespace App\Http\Controllers;

use App\Models\Withdrawal;
use App\Models\SiteSetting;
use App\Services\Blockchain\RaceCoinDirectMintService;
use App\Services\Income\IncomeAccrualService;
use App\Services\Income\WalletBalanceService;
use App\Services\Income\WithdrawalService;
use App\Support\RewardPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WithdrawalController extends Controller
{
    public function index(Request $request, WalletBalanceService $wallets, WithdrawalService $withdrawalService): Response
    {
        $user = $request->user()->loadMissing('userWallet');
        $feeRules = RewardPlan::walletWithdrawalFeeRulesForUi();
        $incomePolicy = app(IncomeAccrualService::class)->summaryForUser($user, $wallets);

        $raceMintOnWithdraw = app(RaceCoinDirectMintService::class)->enabled();

        $withdrawals = Withdrawal::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get([
                'id',
                'amount_usd',
                'fee_usd',
                'team_reward_usd',
                'admin_fee_usd',
                'net_usd',
                'race_amount',
                'race_tx_hash',
                'status',
                'created_at',
            ])
            ->map(static fn (Withdrawal $w) => [
                'id' => $w->id,
                'amount_usd' => $w->amount_usd,
                'fee_usd' => $w->fee_usd,
                'team_reward_usd' => $w->teamRewardUsd(),
                'admin_fee_usd' => $w->adminFeeUsd(),
                'net_usd' => $w->net_usd,
                'race_amount' => $w->race_amount,
                'race_tx_hash' => $w->race_tx_hash,
                'status' => $w->status,
                'status_label' => Withdrawal::statusLabels()[$w->status] ?? ucfirst((string) $w->status),
                'created_at' => $w->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $buckets = $wallets->incomeBuckets($user);

        return Inertia::render('Withdrawal', [
            'race_level_income' => app(\App\Services\Blockchain\OnChainLevelIncomeRows::class)->totalsForUser($user),
            'balance_usd' => $buckets['total_usd'],
            'income_buckets' => $buckets,
            'stake_auto_claim_live' => SiteSetting::stakeMintClaimEnabled(),
            'income_policy' => $incomePolicy,
            'wallet_address' => $user->wallet_address,
            'withdrawal_fee' => $feeRules,
            'admin_wallet' => [
                'address' => SiteSetting::treasuryAddress(),
                'network' => SiteSetting::treasuryNetworkLabel(),
            ],
            'withdrawals_disabled' => ! $user->canRequestWithdrawal(),
            'withdrawal_lock_message' => $user->withdrawalLockMessage(),
            'withdrawals_locked_until' => $user->withdrawals_locked_until?->toIso8601String(),
            'rate_limit' => $withdrawalService->rateLimitStatus($user),
            'race_mint_on_withdraw' => $raceMintOnWithdraw,
            'withdrawals' => $withdrawals,
        ]);
    }

    public function store(Request $request, WithdrawalService $withdrawals): RedirectResponse
    {
        $validated = $request->validate([
            'amount_usd' => ['required', 'numeric', 'min:1', 'max:999999.99'],
            'destination_address' => ['required', 'string', 'min:10', 'max:255'],
            'income_bucket' => ['required', 'string', 'in:daily_claim,other'],
        ]);

        try {
            $withdrawal = $withdrawals->request(
                $request->user(),
                (string) $validated['amount_usd'],
                (string) $validated['destination_address'],
                (string) $validated['income_bucket'],
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', __('Withdrawal could not be completed. Please try again.'));
        }

        if ($withdrawal->status === Withdrawal::STATUS_COMPLETED && filled($withdrawal->race_tx_hash)) {
            return back()->with(
                'status',
                __('RACE minted to your wallet. Net :net USDT → :race RACE (tx :tx). Team Reward :team + Admin Fee :admin deducted.', [
                    'net' => number_format((float) $withdrawal->net_usd, 2),
                    'race' => number_format((float) $withdrawal->race_amount, 4),
                    'tx' => \Illuminate\Support\Str::limit((string) $withdrawal->race_tx_hash, 18, '…'),
                    'team' => number_format((float) $withdrawal->teamRewardUsd(), 2),
                    'admin' => number_format((float) $withdrawal->adminFeeUsd(), 2),
                ]),
            );
        }

        return back()->with(
            'status',
            __('Withdrawal request submitted. It will be processed after admin approval. Net :net USDT (Team Reward :team + Admin Fee :admin deducted).', [
                'net' => number_format((float) $withdrawal->net_usd, 2),
                'team' => number_format((float) $withdrawal->teamRewardUsd(), 2),
                'admin' => number_format((float) $withdrawal->adminFeeUsd(), 2),
            ]),
        );
    }
}
