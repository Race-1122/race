<?php

namespace App\Services\Income;

use App\Models\IncomeWalletTransaction;
use App\Models\LedgerEntry;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Blockchain\RaceCoinDirectMintService;
use App\Services\Blockchain\UsdtTransferVerifier;
use App\Support\RewardPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WithdrawalService
{
    public const ADMIN_FEE_SOURCE = 'cash_out_admin_fee';

    public function __construct(
        protected LedgerWriter $ledger,
        protected CompanyReserveLedger $companyReserve,
        protected WalletBalanceService $wallets,
        protected AffiliateTeamRewardsService $teamRewards,
        protected UsdtTransferVerifier $usdtVerifier,
    ) {}

    /**
     * Rolling-window rate limit status for UI + enforcement.
     *
     * @return array{
     *     enabled: bool,
     *     max_per_window: int,
     *     window_hours: int,
     *     used_in_window: int,
     *     remaining: int,
     *     can_request: bool,
     *     next_available_at: string|null,
     *     slot_frees_at: list<string>
     * }
     */
    public function rateLimitStatus(User $user): array
    {
        $rules = RewardPlan::walletWithdrawalRateLimit();
        $max = $rules['max_per_window'];
        $hours = $rules['window_hours'];
        $enabled = $rules['enabled'];

        if (! $enabled) {
            return [
                'enabled' => false,
                'max_per_window' => $max,
                'window_hours' => $hours,
                'used_in_window' => 0,
                'remaining' => $max,
                'can_request' => true,
                'next_available_at' => null,
                'slot_frees_at' => [],
            ];
        }

        $windowStart = now()->subHours($hours);
        $recent = Withdrawal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>', $windowStart)
            ->orderBy('created_at')
            ->get(['id', 'created_at']);

        $used = $recent->count();
        $slotFreesAt = $recent
            ->map(static fn (Withdrawal $w) => $w->created_at?->copy()->addHours($hours)?->toIso8601String())
            ->filter()
            ->values()
            ->all();

        $canRequest = $used < $max;
        $nextAvailable = null;
        if (! $canRequest && $recent->isNotEmpty()) {
            $oldest = $recent->first();
            $nextAvailable = $oldest->created_at?->copy()->addHours($hours)?->toIso8601String();
        }

        return [
            'enabled' => true,
            'max_per_window' => $max,
            'window_hours' => $hours,
            'used_in_window' => $used,
            'remaining' => max(0, $max - $used),
            'can_request' => $canRequest,
            'next_available_at' => $nextAvailable,
            'slot_frees_at' => $slotFreesAt,
        ];
    }

    /**
     * Create a user income / USDT wallet withdrawal request.
     *
     * Server-side dual fee (do not trust client):
     *   Team Reward = 10% of gross → L1–L10 (always from income)
     *   Admin Fee   = $1 if gross < 100; else 2% → paid as USDT (fee-first mint path)
     *                   or deducted from income net (legacy pending / no-mint path)
     *
     * When race_income_mint.enabled (fee-first):
     *   Admin Fee is paid on-chain as USDT first; income net = gross − team only.
     *   Mint that net as RACE (no admin approve).
     * Otherwise: net = gross − team − admin; gross reserved until admin approval debit.
     *
     * Does NOT apply to fixed maturity / EMI / Engine Flexible stake exit.
     */
    public function request(
        User $user,
        string $grossAmountUsd,
        string $destinationAddress,
        string $incomeBucket = VirtualIncomeWalletService::BUCKET_OTHER,
        ?string $adminFeeTxHash = null,
    ): Withdrawal {
        if (\App\Support\IncomeVaultFinancialAuthority::isAuthoritative()
            && ! config('income_vault.legacy_withdrawal_enabled', true)) {
            throw ValidationException::withMessages([
                'amount_usd' => __('Use on-chain RaceIncomeVault withdrawal when authoritative mode is enabled.'),
            ]);
        }

        $incomeBucket = $this->normalizeIncomeBucket($incomeBucket);

        $mintService = app(RaceCoinDirectMintService::class);
        $mintOnWithdraw = $mintService->enabled();

        if (! $mintOnWithdraw) {
            \App\Support\BlockchainMode::assertReadOnlyRewards();
        }

        if (! $user->canRequestWithdrawal()) {
            throw ValidationException::withMessages([
                'amount_usd' => __($user->withdrawalLockMessage()
                    ?? 'Withdrawals are disabled for your account. Contact support.'),
            ]);
        }

        // Stake ROI already paid by RACE mint is recouped first if the Engine paid the same days again.
        $stakeMint = app(\App\Services\Income\StakeMintClaimService::class);
        if ($stakeMint->hasOpenAdvance($user)) {
            try {
                $stakeMint->reconcileUser($user);
            } catch (\Throwable $e) {
                report($e);
                throw ValidationException::withMessages([
                    'amount_usd' => __('Could not verify your stake claims on-chain right now. Please try again in a minute.'),
                ]);
            }
        }

        $rate = $this->rateLimitStatus($user);
        if ($rate['enabled'] && ! $rate['can_request']) {
            throw ValidationException::withMessages([
                'amount_usd' => __(
                    'Limit: :max withdrawals per :hours hours. Next available after :when.',
                    [
                        'max' => $rate['max_per_window'],
                        'hours' => $rate['window_hours'],
                        'when' => $rate['next_available_at']
                            ? \Illuminate\Support\Carbon::parse($rate['next_available_at'])->timezone(config('app.timezone'))->format('d M Y, h:i A')
                            : __('24 hours'),
                    ],
                ),
            ]);
        }

        $min = number_format((float) (RewardPlan::walletWithdrawalFeeRulesForUi()['min_amount_usd'] ?? 1), 2, '.', '');
        $gross = number_format((float) $grossAmountUsd, 2, '.', '');
        if (bccomp($gross, $min, 2) < 0) {
            throw ValidationException::withMessages([
                'amount_usd' => __('Minimum withdrawal is :min USDT.', ['min' => $min]),
            ]);
        }

        $bucketAvailable = app(VirtualIncomeWalletService::class)->availableInBucket($user, $incomeBucket);
        if (bccomp($bucketAvailable, $gross, 2) < 0) {
            $label = $incomeBucket === VirtualIncomeWalletService::BUCKET_DAILY_CLAIM
                ? __('Daily claim income')
                : __('Other income');
            throw ValidationException::withMessages([
                'amount_usd' => __('Insufficient :label balance (:have USDT available).', [
                    'label' => $label,
                    'have' => $bucketAvailable,
                ]),
            ]);
        }

        $storedAddress = trim((string) ($user->wallet_address ?? ''));
        $destination = $storedAddress !== ''
            ? $storedAddress
            : trim($destinationAddress);
        if ($destination === '' || strlen($destination) < 10) {
            throw ValidationException::withMessages([
                'destination_address' => __('Enter a valid BEP20 wallet address.'),
            ]);
        }

        $feeCalc = RewardPlan::calculateWalletWithdrawalFee($gross);
        $teamReward = $feeCalc['team_reward_usd'];
        $adminFee = $feeCalc['admin_fee_usd'];
        // Fee-first (RACE mint): Admin Fee is paid in USDT from the user's wallet — do not
        // subtract it again from income. Income loses only the 10% Team Reward.
        $net = RewardPlan::incomeNetUsd($gross, $mintOnWithdraw);
        if (bccomp($net, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'amount_usd' => $mintOnWithdraw
                    ? __('Amount too small after Team Reward (10%). Increase the withdrawal amount.')
                    : __('Amount too small after Team Reward and Admin Fee. Increase the withdrawal amount.'),
            ]);
        }

        if ($mintOnWithdraw) {
            return $this->requestWithRaceMint(
                $user,
                $gross,
                $teamReward,
                $adminFee,
                $net,
                $destination,
                $storedAddress,
                $mintService,
                $incomeBucket,
                $adminFeeTxHash,
            );
        }

        return DB::transaction(function () use ($user, $gross, $teamReward, $adminFee, $net, $destination, $storedAddress, $incomeBucket) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if (! $locked->canRequestWithdrawal()) {
                throw ValidationException::withMessages([
                    'amount_usd' => __($locked->withdrawalLockMessage()
                        ?? 'Withdrawals are disabled for your account. Contact support.'),
                ]);
            }

            $rate = $this->rateLimitStatus($locked);
            if ($rate['enabled'] && ! $rate['can_request']) {
                throw ValidationException::withMessages([
                    'amount_usd' => __(
                        'Limit: :max withdrawals per :hours hours. Next available after :when.',
                        [
                            'max' => $rate['max_per_window'],
                            'hours' => $rate['window_hours'],
                            'when' => $rate['next_available_at']
                                ? \Illuminate\Support\Carbon::parse($rate['next_available_at'])->timezone(config('app.timezone'))->format('d M Y, h:i A')
                                : __('24 hours'),
                        ],
                    ),
                ]);
            }

            $balance = $this->wallets->virtualIncomeBalance($locked);
            if (bccomp($balance, $gross, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount_usd' => __('Insufficient income balance.'),
                ]);
            }
            $bucketBal = app(VirtualIncomeWalletService::class)->availableInBucket($locked, $incomeBucket);
            if (bccomp($bucketBal, $gross, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount_usd' => __('Insufficient balance in selected income type.'),
                ]);
            }

            if ($storedAddress === '') {
                $locked->forceFill(['wallet_address' => $destination])->save();
            }

            $withdrawal = Withdrawal::query()->create([
                'user_id' => $locked->id,
                'amount_usd' => $gross,
                'fee_usd' => $teamReward,
                'team_reward_usd' => $teamReward,
                'admin_fee_usd' => $adminFee,
                'net_usd' => $net,
                'destination_address' => $destination,
                'status' => Withdrawal::STATUS_PENDING,
            ]);

            $this->creditCashOutAdminFee($withdrawal);
            $this->teamRewards->onWithdrawal($locked, $withdrawal);

            app(VirtualIncomeWalletService::class)->debit(
                $locked,
                \App\Models\IncomeWalletTransaction::TYPE_WITHDRAWAL,
                $gross,
                "withdrawal:{$withdrawal->id}",
                "withdrawal_request:{$withdrawal->id}",
                [
                    'withdrawal_id' => $withdrawal->id,
                    'income_bucket' => $incomeBucket,
                    'team_reward_usd' => $teamReward,
                    'admin_fee_usd' => $adminFee,
                    'net_usd' => $net,
                    'note' => 'Gross reserved at request; ledger debit on admin approval',
                    'phase' => 'request_reserved',
                ],
            );

            return $withdrawal;
        });
    }

    private function normalizeIncomeBucket(string $bucket): string
    {
        $bucket = trim(strtolower($bucket));
        if ($bucket === VirtualIncomeWalletService::BUCKET_DAILY_CLAIM) {
            return VirtualIncomeWalletService::BUCKET_DAILY_CLAIM;
        }

        return VirtualIncomeWalletService::BUCKET_OTHER;
    }

    /**
     * Order (locked):
     * 1) Verify Admin Fee USDT paid on-chain from user wallet → treasury
     * 2) Mint net as RACE to user wallet
     * 3) Debit income + distribute Team Reward 10% in DB
     */
    private function requestWithRaceMint(
        User $user,
        string $gross,
        string $teamReward,
        string $adminFee,
        string $net,
        string $destination,
        string $storedAddress,
        RaceCoinDirectMintService $mintService,
        string $incomeBucket = VirtualIncomeWalletService::BUCKET_OTHER,
        ?string $adminFeeTxHash = null,
    ): Withdrawal {
        $destination = strtolower(trim($destination));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $destination)) {
            throw ValidationException::withMessages([
                'destination_address' => __('Enter a valid BEP20 wallet address.'),
            ]);
        }

        if (! $user->canRequestWithdrawal()) {
            throw ValidationException::withMessages([
                'amount_usd' => __($user->withdrawalLockMessage()
                    ?? 'Withdrawals are disabled for your account. Contact support.'),
            ]);
        }

        $balancePre = $this->wallets->virtualIncomeBalance($user);
        if (bccomp($balancePre, $gross, 2) < 0) {
            throw ValidationException::withMessages([
                'amount_usd' => __('Insufficient income balance.'),
            ]);
        }
        $bucketPre = app(VirtualIncomeWalletService::class)->availableInBucket($user, $incomeBucket);
        if (bccomp($bucketPre, $gross, 2) < 0) {
            throw ValidationException::withMessages([
                'amount_usd' => __('Insufficient balance in selected income type.'),
            ]);
        }

        $feeTxHash = strtolower(trim((string) $adminFeeTxHash));
        if (bccomp($adminFee, '0', 2) > 0) {
            if (! preg_match('/^0x[a-f0-9]{64}$/', $feeTxHash)) {
                throw ValidationException::withMessages([
                    'admin_fee_tx_hash' => __('Pay the Admin Fee in USDT from your wallet first, then retry.'),
                ]);
            }

            if (Withdrawal::query()->where('admin_fee_tx_hash', $feeTxHash)->exists()) {
                throw ValidationException::withMessages([
                    'admin_fee_tx_hash' => __('This Admin Fee transaction was already used.'),
                ]);
            }

            $verified = $this->usdtVerifier->verifyTransferToTreasury(
                $feeTxHash,
                (float) $adminFee,
                $destination,
                'withdrawal_admin_fee',
            );
            if (! ($verified['ok'] ?? false)) {
                throw ValidationException::withMessages([
                    'admin_fee_tx_hash' => $verified['reason'] ?? __('Could not verify Admin Fee USDT transfer.'),
                ]);
            }

            $paid = number_format((float) ($verified['amount_usd'] ?? 0), 2, '.', '');
            if (bccomp($paid, $adminFee, 2) < 0) {
                throw ValidationException::withMessages([
                    'admin_fee_tx_hash' => __('Admin Fee must be at least :fee USDT.', [
                        'fee' => $adminFee,
                    ]),
                ]);
            }
        } else {
            $feeTxHash = null;
        }

        $raceCoin = app(RaceCoinService::class);
        $price = $raceCoin->formattedPriceUsd(8);
        $raceAmount = $raceCoin->coinsForUsdt((float) $net);
        if (bccomp($raceAmount, '0', 8) <= 0) {
            throw ValidationException::withMessages([
                'amount_usd' => __('RACE quote is zero — check price / try again.'),
            ]);
        }

        // Mint only after Admin Fee USDT is verified. Debit + team reward after mint success.
        try {
            $minted = $mintService->mintToWallet($destination, $raceAmount);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'amount_usd' => __('RACE mint failed: :msg', [
                    'msg' => \Illuminate\Support\Str::limit($e->getMessage(), 160),
                ]),
            ]);
        }

        try {
            return DB::transaction(function () use (
                $user,
                $gross,
                $teamReward,
                $adminFee,
                $net,
                $destination,
                $storedAddress,
                $raceAmount,
                $price,
                $minted,
                $incomeBucket,
                $feeTxHash,
            ) {
                $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                if (! $locked->canRequestWithdrawal()) {
                    throw ValidationException::withMessages([
                        'amount_usd' => __($locked->withdrawalLockMessage()
                            ?? 'Withdrawals are disabled for your account. Contact support.'),
                    ]);
                }

                $rate = $this->rateLimitStatus($locked);
                if ($rate['enabled'] && ! $rate['can_request']) {
                    throw ValidationException::withMessages([
                        'amount_usd' => __(
                            'Limit: :max withdrawals per :hours hours. Next available after :when.',
                            [
                                'max' => $rate['max_per_window'],
                                'hours' => $rate['window_hours'],
                                'when' => $rate['next_available_at']
                                    ? \Illuminate\Support\Carbon::parse($rate['next_available_at'])->timezone(config('app.timezone'))->format('d M Y, h:i A')
                                    : __('24 hours'),
                            ],
                        ),
                    ]);
                }

                $balance = $this->wallets->virtualIncomeBalance($locked);
                if (bccomp($balance, $gross, 2) < 0) {
                    throw ValidationException::withMessages([
                        'amount_usd' => __('Insufficient income balance.'),
                    ]);
                }
                $bucketBal = app(VirtualIncomeWalletService::class)->availableInBucket($locked, $incomeBucket);
                if (bccomp($bucketBal, $gross, 2) < 0) {
                    throw ValidationException::withMessages([
                        'amount_usd' => __('Insufficient balance in selected income type.'),
                    ]);
                }

                if ($feeTxHash !== null
                    && Withdrawal::query()->where('admin_fee_tx_hash', $feeTxHash)->exists()) {
                    throw ValidationException::withMessages([
                        'admin_fee_tx_hash' => __('This Admin Fee transaction was already used.'),
                    ]);
                }

                if ($storedAddress === '') {
                    $locked->forceFill(['wallet_address' => $destination])->save();
                }

                $withdrawal = Withdrawal::query()->create([
                    'user_id' => $locked->id,
                    'amount_usd' => $gross,
                    'fee_usd' => $teamReward,
                    'team_reward_usd' => $teamReward,
                    'admin_fee_usd' => $adminFee,
                    'net_usd' => $net,
                    'race_amount' => $raceAmount,
                    'race_price_usd' => $price,
                    'race_tx_hash' => $minted['tx_hash'],
                    'admin_fee_tx_hash' => $feeTxHash,
                    'destination_address' => $destination,
                    'status' => Withdrawal::STATUS_COMPLETED,
                ]);

                // Team Reward 10% then ledger Admin Fee (on-chain USDT already paid).
                $this->teamRewards->onWithdrawal($locked, $withdrawal);
                $this->creditCashOutAdminFee($withdrawal);

                app(VirtualIncomeWalletService::class)->debit(
                    $locked,
                    IncomeWalletTransaction::TYPE_WITHDRAWAL,
                    $gross,
                    "withdrawal:{$withdrawal->id}",
                    "withdrawal_race_mint:{$withdrawal->id}",
                    [
                        'withdrawal_id' => $withdrawal->id,
                        'income_bucket' => $incomeBucket,
                        'team_reward_usd' => $teamReward,
                        'admin_fee_usd' => $adminFee,
                        'admin_fee_tx_hash' => $feeTxHash,
                        'net_usd' => $net,
                        'race_amount' => $raceAmount,
                        'race_price_usd' => $price,
                        'race_tx_hash' => $minted['tx_hash'],
                        'note' => 'Admin USDT fee paid on-chain; Team Reward distributed; RACE minted',
                        'phase' => 'race_mint_completed',
                    ],
                );

                $this->ledger->record(
                    $locked,
                    LedgerEntry::TYPE_WALLET_WITHDRAWAL,
                    '-'.$gross,
                    'withdrawal',
                    $withdrawal->id,
                    [
                        'fee_usd' => $teamReward,
                        'team_reward_usd' => $teamReward,
                        'admin_fee_usd' => $adminFee,
                        'admin_fee_tx_hash' => $feeTxHash,
                        'net_usd' => $net,
                        'race_amount' => $raceAmount,
                        'race_tx_hash' => $minted['tx_hash'],
                        'destination_address' => $destination,
                        'income_bucket' => $incomeBucket,
                        'fee_destination' => 'community_team_rewards',
                        'admin_fee_destination' => 'admin_treasury',
                        'admin_fee_settled_as' => 'on_chain_usdt',
                        'settled_as' => 'race_coin_mint',
                        'approved_by_admin' => false,
                    ],
                );

                return $withdrawal;
            });
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages([
                'amount_usd' => __(
                    'RACE was minted (tx :tx) but wallet debit failed. Contact support — do not retry the same amount.',
                    ['tx' => $minted['tx_hash']],
                ),
            ]);
        }
    }

    /**
     * Admin approval: debit full gross from member; fees already accounted on request.
     */
    public function approve(Withdrawal $withdrawal): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal) {
            $w = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($w->status === Withdrawal::STATUS_COMPLETED) {
                $this->ensureFeesDistributed($w);

                return $w;
            }
            if (! in_array($w->status, [Withdrawal::STATUS_PENDING, Withdrawal::STATUS_REJECTED], true)) {
                throw ValidationException::withMessages([
                    'status' => __('Only pending or rejected withdrawals can be marked completed.'),
                ]);
            }

            $user = User::query()->whereKey($w->user_id)->lockForUpdate()->firstOrFail();

            $already = LedgerEntry::query()
                ->where('user_id', $user->id)
                ->where('entry_type', LedgerEntry::TYPE_WALLET_WITHDRAWAL)
                ->where('reference_type', 'withdrawal')
                ->where('reference_id', $w->id)
                ->lockForUpdate()
                ->exists();
            if ($already) {
                $w->forceFill(['status' => Withdrawal::STATUS_COMPLETED])->save();
                $this->ensureFeesDistributed($w);

                return $w;
            }

            $gross = number_format((float) $w->amount_usd, 2, '.', '');
            $balance = $this->wallets->virtualIncomeBalance($user);
            if (bccomp($balance, $gross, 2) < 0) {
                throw ValidationException::withMessages([
                    'amount_usd' => __('Insufficient Virtual Income Wallet balance at approval time.'),
                ]);
            }

            $this->ensureFeesDistributed($w);

            $teamReward = $w->teamRewardUsd();
            $adminFee = $w->adminFeeUsd();

            $this->ledger->record(
                $user,
                LedgerEntry::TYPE_WALLET_WITHDRAWAL,
                '-'.$gross,
                'withdrawal',
                $w->id,
                [
                    'fee_usd' => $teamReward,
                    'team_reward_usd' => $teamReward,
                    'admin_fee_usd' => $adminFee,
                    'net_usd' => number_format((float) $w->net_usd, 2, '.', ''),
                    'destination_address' => (string) $w->destination_address,
                    'fee_destination' => 'community_team_rewards',
                    'admin_fee_destination' => 'admin_treasury',
                    'approved_by_admin' => true,
                ],
            );

            $w->forceFill(['status' => Withdrawal::STATUS_COMPLETED])->save();

            return $w;
        });
    }

    private function ensureFeesDistributed(Withdrawal $withdrawal): void
    {
        $this->creditCashOutAdminFee($withdrawal);

        $user = User::query()->find($withdrawal->user_id);
        if ($user) {
            $this->teamRewards->onWithdrawal($user, $withdrawal);
        }
    }

    /**
     * Record the SEPARATE cash-out Admin Fee ($1 / 1%) to company/treasury ledger.
     * Distinct from unqualified Team Reward remainder (also TYPE_WITHDRAWAL_ADMIN_FEE + different meta).
     */
    private function creditCashOutAdminFee(Withdrawal $withdrawal): void
    {
        $adminFee = $withdrawal->adminFeeUsd();
        if (bccomp($adminFee, '0', 2) <= 0) {
            return;
        }

        $exists = LedgerEntry::query()
            ->where('entry_type', LedgerEntry::TYPE_WITHDRAWAL_ADMIN_FEE)
            ->where('reference_type', 'withdrawal')
            ->where('reference_id', $withdrawal->id)
            ->where('meta->source', self::ADMIN_FEE_SOURCE)
            ->exists();
        if ($exists) {
            return;
        }

        $gross = number_format((float) $withdrawal->amount_usd, 2, '.', '');

        $feeTx = $withdrawal->admin_fee_tx_hash
            ? strtolower(trim((string) $withdrawal->admin_fee_tx_hash))
            : null;

        $this->ledger->record(
            $this->companyReserve->user(),
            LedgerEntry::TYPE_WITHDRAWAL_ADMIN_FEE,
            $adminFee,
            'withdrawal',
            $withdrawal->id,
            [
                'source' => self::ADMIN_FEE_SOURCE,
                'from_user_id' => $withdrawal->user_id,
                'withdrawal_gross_usd' => $gross,
                'team_reward_usd' => $withdrawal->teamRewardUsd(),
                'admin_fee_usd' => $adminFee,
                'admin_fee_tx_hash' => $feeTx,
                'settled_as' => $feeTx ? 'on_chain_usdt' : 'ledger_only',
                'note' => $feeTx
                    ? 'Income withdrawal Admin Fee paid on-chain as USDT to treasury'
                    : 'Income withdrawal Admin Fee ($1 flat under $100, else 2%)',
                'admin_wallet_bep20' => SiteSetting::treasuryAddress(),
                'network' => SiteSetting::treasuryNetworkLabel(),
            ],
        );
    }

    public function reject(Withdrawal $withdrawal): Withdrawal
    {
        return DB::transaction(function () use ($withdrawal) {
            $w = Withdrawal::query()->whereKey($withdrawal->id)->lockForUpdate()->firstOrFail();
            if ($w->status === Withdrawal::STATUS_COMPLETED) {
                throw ValidationException::withMessages([
                    'status' => __('Completed withdrawals cannot be rejected.'),
                ]);
            }
            if ($w->status === Withdrawal::STATUS_REJECTED) {
                return $w;
            }
            if ($w->status !== Withdrawal::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'status' => __('Only pending withdrawals can be rejected.'),
                ]);
            }

            $user = User::query()->whereKey($w->user_id)->lockForUpdate()->firstOrFail();
            $gross = number_format((float) $w->amount_usd, 2, '.', '');
            $refundKey = "withdrawal_reject_refund:{$w->id}";

            $existingRefund = IncomeWalletTransaction::query()
                ->where('user_id', $user->id)
                ->where('idempotency_key', $refundKey)
                ->exists();
            if (! $existingRefund) {
                app(VirtualIncomeWalletService::class)->credit(
                    $user,
                    IncomeWalletTransaction::TYPE_ADJUSTMENT,
                    $gross,
                    "withdrawal:{$w->id}",
                    $refundKey,
                    [
                        'withdrawal_id' => $w->id,
                        'reason' => 'withdrawal_rejected',
                        'note' => 'Virtual income restored after admin rejection',
                    ],
                );
            }

            $w->forceFill(['status' => Withdrawal::STATUS_REJECTED])->save();

            return $w;
        });
    }
}
