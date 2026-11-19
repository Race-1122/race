<?php

namespace App\Services\Income;

use App\Models\IncomeWalletTransaction;
use App\Models\StakeMintAdvance;
use App\Models\StakeMintClaim;
use App\Models\User;
use App\Models\VirtualStakeCompound;
use App\Services\Blockchain\CommunityEngineRpcReader;
use App\Services\Blockchain\RaceCoinDirectMintService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Claim remaining Engine stakes (after the one on-chain claim / 24h) into Virtual Income (USDT-notional).
 *
 * No RACE is minted on this claim. When the member later withdraws Virtual → wallet, the existing
 * WithdrawalService path applies: Admin Fee + 10% Team Reward, then RaceCoin.mint(net) to the wallet
 * (requires Multisig setMinter + RACE_INCOME_MINT_ON_WITHDRAW).
 *
 * The Engine still counts those days as owed, so every virtual credit is tracked as an "advance" per stake.
 * When the Engine later pays the same days, the advance is recouped from virtual income — no double pay.
 */
class StakeMintClaimService
{
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        protected RaceCoinDirectMintService $mint,
        protected IncomeClaimService $window,
        protected WalletBalanceService $wallets,
        protected VirtualIncomeWalletService $virtualWallet,
    ) {}

    public function enabled(): bool
    {
        // Admin "Go live" only. Mint/minter is required later at virtual→wallet withdraw, not at claim.
        return \App\Models\SiteSetting::stakeMintClaimEnabled();
    }

    // ── Chain reads (overridable in tests) ──────────────────────────────────

    protected function reader(): ?CommunityEngineRpcReader
    {
        return CommunityEngineRpcReader::fromConfig();
    }

    /** @return array<string, mixed>|null */
    protected function readStake(string $wallet, int $index): ?array
    {
        return $this->reader()?->readStakeAt($wallet, $index);
    }

    protected function readStakeCount(string $wallet): ?int
    {
        return $this->reader()?->readStakeCount($wallet);
    }

    /**
     * @param  array<string, mixed>|null  $stake
     * @return array<string, mixed>|null
     */
    protected function applyVirtualCompoundOverlay(User $user, int $stakeIndex, ?array $stake): ?array
    {
        if ($stake === null) {
            return null;
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('virtual_stake_compounds')) {
            return $stake;
        }
        $extra = (string) (VirtualStakeCompound::query()
            ->where('user_id', $user->id)
            ->where('stake_index', $stakeIndex)
            ->sum('usdt_amount') ?? '0');
        if (bccomp($extra, '0', 8) > 0) {
            $stake['principal_usdt'] = bcadd((string) ($stake['principal_usdt'] ?? '0'), $extra, 18);
        }

        return $stake;
    }

    protected function readNextAllowedClaimAt(string $wallet): ?int
    {
        return $this->reader()?->readNextAllowedClaimAt($wallet);
    }

    protected function now(): int
    {
        return now()->getTimestamp();
    }

    // ── Pure calculations ───────────────────────────────────────────────────

    /**
     * Whole days the Engine still owes for this stake (before subtracting mint advances).
     *
     * @param  array<string, mixed>  $stake
     */
    public function engineOwedDays(array $stake, int $now): int
    {
        if (! empty($stake['withdrawn'])) {
            return 0;
        }
        if ((int) ($stake['daily_rate_bps'] ?? 0) <= 0) {
            return 0; // matured (rate zeroed) or no reward rate
        }
        if (bccomp((string) ($stake['principal_usdt'] ?? '0'), '0', 18) <= 0) {
            return 0;
        }

        $lastRewardAt = (int) ($stake['last_reward_at'] ?? 0);
        $lockSeconds = (int) ($stake['lock_seconds'] ?? 0);
        $unlockAt = (int) ($stake['unlock_at'] ?? 0);
        $end = $lockSeconds === 0 ? $now : min($now, $unlockAt);
        if ($lastRewardAt <= 0 || $end <= $lastRewardAt) {
            return 0;
        }

        return intdiv($end - $lastRewardAt, self::SECONDS_PER_DAY);
    }

    /**
     * Advance days still outstanding after the Engine settled days since our last snapshot (pure).
     *
     * @param  array<string, mixed>  $stake
     * @return array{days: int, absorbed: int}
     */
    public function projectAdvance(?StakeMintAdvance $adv, array $stake): array
    {
        $outstanding = (int) ($adv?->days_outstanding ?? 0);
        if ($adv === null || $outstanding <= 0) {
            return ['days' => 0, 'absorbed' => 0];
        }

        $snapshot = (int) ($adv->last_onchain_reward_at ?? 0);
        $current = (int) ($stake['last_reward_at'] ?? 0);
        $settled = $snapshot > 0 && $current > $snapshot
            ? intdiv($current - $snapshot, self::SECONDS_PER_DAY)
            : 0;
        $absorbed = min($outstanding, $settled);

        return ['days' => $outstanding - $absorbed, 'absorbed' => $absorbed];
    }

    /**
     * Days claimable by mint = Engine owed days − advance days not yet absorbed (capped per claim).
     *
     * @param  array<string, mixed>  $stake
     */
    public function eligibleDays(array $stake, ?StakeMintAdvance $adv, int $now): int
    {
        $owed = $this->engineOwedDays($stake, $now);
        $advance = $this->projectAdvance($adv, $stake)['days'];
        $max = max(1, (int) config('race_income_mint.stake_claim_max_days', 30));

        return max(0, min($owed - $advance, $max));
    }

    /**
     * Same formula as the Engine, truncated.
     *
     * @param  array<string, mixed>  $stake
     * @return array{usdt: string, race: string}
     */
    public function rewardFor(array $stake, int $days): array
    {
        $price = (string) ($stake['reward_price_usdt'] ?? '0');
        if ($days <= 0 || bccomp($price, '0', 18) <= 0) {
            return ['usdt' => '0.00000000', 'race' => '0.00000000'];
        }

        $usdt = bcdiv(
            bcmul(bcmul((string) $stake['principal_usdt'], (string) ((int) $stake['daily_rate_bps']), 18), (string) $days, 18),
            '10000',
            8,
        );
        $race = bcdiv($usdt, $price, 8);

        return ['usdt' => $usdt, 'race' => $race];
    }

    // ── Read model for the dashboard ────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        $base = [
            'enabled' => $this->enabled(),
            'window_open' => $this->window->isWindowOpen(),
            'window_label' => $this->window->windowLabel(),
            'cooldown_active' => false,
            'next_allowed_at' => 0,
            'can_claim' => false,
            'debt_usdt' => '0.00000000',
            'stakes' => [],
            'recent' => [],
        ];
        if (! $base['enabled']) {
            return $base;
        }

        $wallet = $this->walletOf($user);
        if ($wallet === null) {
            return $base;
        }

        $now = $this->now();
        $next = $this->readNextAllowedClaimAt($wallet);
        $count = $this->readStakeCount($wallet);
        if ($next === null || $count === null) {
            $base['error'] = __('Could not read the Engine right now. Try again shortly.');

            return $base;
        }

        $base['next_allowed_at'] = $next;
        $base['cooldown_active'] = $next > $now;

        $advances = StakeMintAdvance::query()->where('user_id', $user->id)->get()->keyBy('stake_index');
        for ($i = 0; $i < $count; $i++) {
            $stake = $this->applyVirtualCompoundOverlay($user, $i, $this->readStake($wallet, $i));
            if ($stake === null) {
                continue;
            }
            $adv = $advances->get($i);
            $days = $this->eligibleDays($stake, $adv, $now);
            $reward = $this->rewardFor($stake, $days);
            if ($days <= 0 && (int) ($adv?->days_outstanding ?? 0) <= 0) {
                continue;
            }
            $base['stakes'][] = [
                'index' => $i,
                'principal_usdt' => $stake['principal_usdt'],
                'daily_rate_bps' => (int) $stake['daily_rate_bps'],
                'eligible_days' => $days,
                'reward_usdt' => $reward['usdt'],
                'reward_race' => $reward['race'],
                'advance_days' => $this->projectAdvance($adv, $stake)['days'],
            ];
        }

        $base['debt_usdt'] = (string) ($advances->sum('recoup_pending_usdt') ?? '0');
        $base['can_claim'] = $base['window_open'] && $base['cooldown_active'];
        $base['recent'] = StakeMintClaim::query()
            ->where('user_id', $user->id)
            ->where('status', StakeMintClaim::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'stake_index', 'days', 'race_amount', 'usdt_amount', 'tx_hash', 'created_at'])
            ->toArray();

        return $base;
    }

    // ── Claim ───────────────────────────────────────────────────────────────

    /**
     * @param  array{from_cron?: bool, skip_onchain_gate?: bool, force?: bool}  $options
     */
    public function claim(User $user, int $stakeIndex, array $options = []): StakeMintClaim
    {
        $force = (bool) ($options['force'] ?? false);
        if (! $this->enabled() && ! $force) {
            throw ValidationException::withMessages(['claim' => __('Claim for remaining stakes is not enabled yet.')]);
        }

        $fromCron = (bool) ($options['from_cron'] ?? false);
        $skipOnchainGate = (bool) ($options['skip_onchain_gate'] ?? false) || $fromCron;

        $wallet = $this->walletOf($user);
        if ($wallet === null) {
            throw ValidationException::withMessages(['claim' => __('Bind a valid BEP20 wallet first.')]);
        }

        if (! $fromCron && ! $this->window->isWindowOpen()) {
            throw ValidationException::withMessages([
                'claim' => __('Claim is open only :window.', ['window' => $this->window->windowLabel()]),
            ]);
        }

        $lock = Cache::lock('stake-mint-claim:'.$user->id, 180);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['claim' => __('A claim is already in progress. Please wait.')]);
        }

        try {
            $this->reconcileUser($user);

            $next = $this->readNextAllowedClaimAt($wallet);
            if ($next === null) {
                throw ValidationException::withMessages(['claim' => __('Could not read the Engine right now. Try again shortly.')]);
            }
            $now = $this->now();
            // Manual UI: only after the one on-chain claim (wallet cooldown). Cron credits all stakes (recoup if Engine pays later).
            if (! $skipOnchainGate && $next <= $now) {
                throw ValidationException::withMessages([
                    'claim' => __('Claim one stake on-chain first. This option is only for your other stakes after that claim.'),
                ]);
            }

            $stake = $this->applyVirtualCompoundOverlay(
                $user,
                $stakeIndex,
                $this->readStake($wallet, $stakeIndex),
            );
            if ($stake === null) {
                throw ValidationException::withMessages(['claim' => __('Stake not found on-chain.')]);
            }

            $adv = StakeMintAdvance::query()
                ->where('user_id', $user->id)
                ->where('stake_index', $stakeIndex)
                ->first();
            $days = $this->eligibleDays($stake, $adv, $now);
            if ($fromCron) {
                // Daily job: at most 1 day per stake per run (missed days catch up on later mornings).
                $days = min($days, max(1, (int) config('race_income_mint.stake_claim_auto_max_days', 1)));
            }
            if ($days <= 0) {
                throw ValidationException::withMessages(['claim' => __('Nothing to claim for this stake yet.')]);
            }

            $reward = $this->rewardFor($stake, $days);
            $usdt4 = number_format((float) $reward['usdt'], 4, '.', '');
            if (bccomp($reward['race'], '0', 8) <= 0 || bccomp($usdt4, '0.0001', 4) < 0) {
                throw ValidationException::withMessages(['claim' => __('Reward is too small to claim yet.')]);
            }

            return DB::transaction(function () use ($user, $wallet, $stakeIndex, $days, $reward, $usdt4, $stake, $fromCron) {
                $claim = StakeMintClaim::query()->create([
                    'user_id' => $user->id,
                    'stake_index' => $stakeIndex,
                    'wallet_address' => $wallet,
                    'days' => $days,
                    'usdt_amount' => $reward['usdt'],
                    'race_amount' => $reward['race'],
                    'race_price_usdt' => bcadd((string) $stake['reward_price_usdt'], '0', 8),
                    'status' => StakeMintClaim::STATUS_PENDING,
                    'meta' => [
                        'engine_last_reward_at' => (int) $stake['last_reward_at'],
                        'settlement' => 'virtual_income',
                        'from_cron' => $fromCron,
                        'note' => $fromCron
                            ? 'Daily auto-claim → Virtual Income (04:00 IST)'
                            : 'Credited to Virtual Income; RACE mint + fees on withdraw',
                    ],
                ]);

                // Virtual USDT only — no RaceCoin.mint here. Withdraw path takes fees then mints.
                $this->virtualWallet->credit(
                    $user,
                    IncomeWalletTransaction::TYPE_INCOME_CLAIM,
                    $usdt4,
                    "stake_mint_claim:{$claim->id}",
                    "stake_mint_claim:{$claim->id}",
                    [
                        'stake_index' => $stakeIndex,
                        'days' => $days,
                        'race_amount' => $reward['race'],
                        'race_price_usdt' => $stake['reward_price_usdt'],
                        'usdt_amount' => $reward['usdt'],
                        'claim_id' => $claim->id,
                        'from_cron' => $fromCron,
                        'note' => $fromCron
                            ? 'Daily auto staking ROI → Virtual Income'
                            : 'Remaining-stake ROI credited to Virtual Income (fees + RACE mint on withdraw)',
                    ],
                );

                $row = StakeMintAdvance::query()
                    ->where('user_id', $user->id)
                    ->where('stake_index', $stakeIndex)
                    ->lockForUpdate()
                    ->first()
                    ?? new StakeMintAdvance(['user_id' => $user->id, 'stake_index' => $stakeIndex]);

                $row->days_outstanding = (int) $row->days_outstanding + $days;
                $row->usdt_outstanding = bcadd((string) ($row->usdt_outstanding ?? '0'), $reward['usdt'], 8);
                $row->race_minted_total = bcadd((string) ($row->race_minted_total ?? '0'), $reward['race'], 8);
                $row->last_onchain_reward_at = (int) $stake['last_reward_at'];
                $row->save();

                $claim->forceFill([
                    'status' => StakeMintClaim::STATUS_COMPLETED,
                    'tx_hash' => null,
                ])->save();

                return $claim->refresh();
            });
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('stake virtual claim failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            throw ValidationException::withMessages([
                'claim' => __('Could not credit Virtual Income: :msg', [
                    'msg' => Str::limit($e->getMessage(), 160),
                ]),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Credit every Engine stake (4, 5, 6…) that has ≥1 day owed into Virtual Income.
     * Each stake uses its own principal and daily ROI rate. No wallet claimReward tx.
     *
     * @param  array{force?: bool}  $options
     * @return array{claims: int, usdt: string, skipped: int, errors: list<string>}
     */
    public function autoClaimUser(User $user, array $options = []): array
    {
        $out = ['claims' => 0, 'usdt' => '0.0000', 'skipped' => 0, 'errors' => []];
        if (! $this->enabled() && ! (bool) ($options['force'] ?? false)) {
            $out['errors'][] = 'disabled';

            return $out;
        }

        $wallet = $this->walletOf($user);
        if ($wallet === null) {
            $out['skipped']++;

            return $out;
        }

        $count = $this->readStakeCount($wallet);
        if ($count === null || $count <= 0) {
            $out['skipped']++;

            return $out;
        }

        for ($i = 0; $i < $count; $i++) {
            try {
                $claim = $this->claim($user, $i, [
                    'from_cron' => true,
                    'skip_onchain_gate' => true,
                    'force' => (bool) ($options['force'] ?? false),
                ]);
                $out['claims']++;
                $out['usdt'] = bcadd($out['usdt'], number_format((float) $claim->usdt_amount, 4, '.', ''), 4);
            } catch (ValidationException $e) {
                $msg = (string) collect($e->errors())->flatten()->first();
                if (str_contains(strtolower($msg), 'nothing to claim')) {
                    $out['skipped']++;
                } else {
                    $out['errors'][] = "stake {$i}: {$msg}";
                }
            } catch (\Throwable $e) {
                $out['errors'][] = "stake {$i}: ".$e->getMessage();
            }
        }

        return $out;
    }

    // ── Recoup (advance absorbed by Engine payments) ────────────────────────

    /** Cheap DB check — true when the member has any advance still to reconcile. */
    public function hasOpenAdvance(User $user): bool
    {
        return StakeMintAdvance::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('days_outstanding', '>', 0)->orWhere('recoup_pending_usdt', '>', 0))
            ->exists();
    }

    /**
     * Compare each advance with the Engine's current lastRewardAt; days the Engine paid again are recouped
     * from virtual income (any shortfall stays pending and is taken from the next available balance).
     *
     * @throws \RuntimeException when the chain cannot be read (fail closed)
     */
    public function reconcileUser(User $user): void
    {
        if (! $this->hasOpenAdvance($user)) {
            return;
        }

        $wallet = $this->walletOf($user);
        if ($wallet === null) {
            return;
        }

        $rows = StakeMintAdvance::query()
            ->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('days_outstanding', '>', 0)->orWhere('recoup_pending_usdt', '>', 0))
            ->get();

        foreach ($rows as $row) {
            if ((int) $row->days_outstanding > 0) {
                $stake = $this->readStake($wallet, (int) $row->stake_index);
                if ($stake === null) {
                    throw new \RuntimeException('Could not read stake '.$row->stake_index.' from the Engine.');
                }
                $this->applyAbsorption($row, $stake);
            }
            $this->settlePending($user, $row);
        }
    }

    /**
     * @param  array<string, mixed>  $stake
     */
    protected function applyAbsorption(StakeMintAdvance $row, array $stake): void
    {
        DB::transaction(function () use ($row, $stake) {
            /** @var StakeMintAdvance $locked */
            $locked = StakeMintAdvance::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $projection = $this->projectAdvance($locked, $stake);
            $absorbed = $projection['absorbed'];

            if ($absorbed > 0) {
                $outstandingDays = (int) $locked->days_outstanding;
                $recoup = $absorbed >= $outstandingDays
                    ? (string) $locked->usdt_outstanding
                    : bcdiv(bcmul((string) $locked->usdt_outstanding, (string) $absorbed, 8), (string) $outstandingDays, 8);

                $locked->days_outstanding = $outstandingDays - $absorbed;
                $locked->usdt_outstanding = bcsub((string) $locked->usdt_outstanding, $recoup, 8);
                $locked->recoup_pending_usdt = bcadd((string) $locked->recoup_pending_usdt, $recoup, 8);
            }

            $current = (int) ($stake['last_reward_at'] ?? 0);
            if ($current > (int) $locked->last_onchain_reward_at) {
                $locked->last_onchain_reward_at = $current;
            }
            $locked->save();
            $row->setRawAttributes($locked->getAttributes(), true);
        });
    }

    protected function settlePending(User $user, StakeMintAdvance $row): void
    {
        $pending = (string) $row->recoup_pending_usdt;
        if (bccomp($pending, '0', 8) <= 0) {
            return;
        }

        $pendingFloor = bcdiv($pending, '1', 4);
        if (bccomp($pendingFloor, '0.0001', 4) < 0) {
            // Sub-cent dust (below ledger precision) — clear it.
            $row->forceFill(['recoup_pending_usdt' => '0'])->save();

            return;
        }

        $balanceFloor = bcdiv($this->wallets->virtualIncomeBalance($user), '1', 4);
        $pay = bccomp($balanceFloor, $pendingFloor, 4) >= 0 ? $pendingFloor : $balanceFloor;
        if (bccomp($pay, '0.0001', 4) < 0) {
            return;
        }

        try {
            $this->virtualWallet->debit(
                $user,
                IncomeWalletTransaction::TYPE_ADJUSTMENT,
                $pay,
                "stake_mint_recoup:{$row->id}",
                "stake_mint_recoup:{$row->id}:".(string) Str::uuid(),
                [
                    'stake_index' => $row->stake_index,
                    'note' => 'Recoup: stake ROI already paid by RACE mint was settled again by the Engine',
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('stake mint recoup debit failed', ['advance_id' => $row->id, 'error' => $e->getMessage()]);

            return;
        }

        DB::transaction(function () use ($row, $pay) {
            $locked = StakeMintAdvance::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $left = bcsub((string) $locked->recoup_pending_usdt, $pay, 8);
            $locked->recoup_pending_usdt = bccomp($left, '0.0001', 8) >= 0 ? $left : '0';
            $locked->save();
            $row->setRawAttributes($locked->getAttributes(), true);
        });
    }

    protected function walletOf(User $user): ?string
    {
        $wallet = strtolower(trim((string) ($user->wallet_address ?? '')));

        return preg_match('/^0x[a-f0-9]{40}$/', $wallet) ? $wallet : null;
    }
}
