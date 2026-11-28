<?php

namespace App\Services\Income;

use App\Models\Investment;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\BlockchainMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncomeClaimService
{
    public function __construct(
        protected LedgerWriter $ledger,
        protected IncomeAccrualService $accrual,
    ) {}

    public function claimIntervalHours(): int
    {
        return max(1, (int) config('income.claim.interval_hours', 24));
    }

    public function windowEnabled(): bool
    {
        return (bool) config('income.claim.window_enabled', true);
    }

    public function windowTimezone(): string
    {
        return (string) config('income.claim.timezone', 'Asia/Kolkata');
    }

    /** Window open time on the given local day (default: today). */
    public function windowStartAt(?Carbon $day = null): Carbon
    {
        return $this->windowTime((string) config('income.claim.window_start', '07:00'), $day);
    }

    public function windowEndAt(?Carbon $day = null): Carbon
    {
        return $this->windowTime((string) config('income.claim.window_end', '12:00'), $day);
    }

    private function windowTime(string $hhmm, ?Carbon $day): Carbon
    {
        [$h, $m] = array_pad(explode(':', $hhmm), 2, '0');

        return ($day ?? now())->copy()
            ->timezone($this->windowTimezone())
            ->setTime((int) $h, (int) $m, 0);
    }

    /** True when manual claim is allowed right now (07:00 ≤ now < 12:00 IST). */
    public function isWindowOpen(?Carbon $at = null): bool
    {
        if (! $this->windowEnabled()) {
            return true;
        }

        $at = ($at ?? now())->copy()->timezone($this->windowTimezone());

        return $at->greaterThanOrEqualTo($this->windowStartAt($at)) && $at->lessThan($this->windowEndAt($at));
    }

    /** Next moment the claim window opens (today if not started yet, else tomorrow). */
    public function nextWindowOpenAt(?Carbon $from = null): Carbon
    {
        $from = ($from ?? now())->copy()->timezone($this->windowTimezone());
        $start = $this->windowStartAt($from);

        return $from->lessThan($start) ? $start : $this->windowStartAt($from->copy()->addDay());
    }

    public function windowLabel(): string
    {
        return $this->windowStartAt()->format('h:i A').' – '.$this->windowEndAt()->format('h:i A').' ('.$this->windowTimezone().')';
    }

    /**
     * Next time this user may claim.
     * With window enabled: one claim per local calendar day, inside the window.
     */
    public function nextClaimAt(User $user): ?Carbon
    {
        if ($user->last_income_claim_at === null) {
            return null;
        }

        if ($this->windowEnabled()) {
            $last = $user->last_income_claim_at->copy()->timezone($this->windowTimezone());

            return $this->windowStartAt($last->copy()->addDay());
        }

        return $user->last_income_claim_at->copy()->addHours($this->claimIntervalHours());
    }

    /**
     * Move all accrued staking rewards into virtual USDT wallet. No Admin Fee.
     *
     * @param  array{from_cron?: bool, ignore_cooldown?: bool}  $options
     * @return array{claimed_usd: string, virtual_balance_usd: string}
     */
    public function claimAccruedIncome(User $user, array $options = []): array
    {
        $fromCron = (bool) ($options['from_cron'] ?? false);
        $ignoreCooldown = (bool) ($options['ignore_cooldown'] ?? false);

        // Platform accrued ROI → virtual wallet. Allowed when auto_claim cron is enabled
        // even if REWARDS_ENGINE=blockchain_only (on-chain Engine claim stays separate on ICO page).
        if (! config('income.auto_claim.enabled', true)) {
            BlockchainMode::assertReadOnlyRewards();

            if (config('participation_contract.on_chain.enabled') || BlockchainMode::blockchainOnly()) {
                throw ValidationException::withMessages([
                    'claim' => __('Income claim is for platform staking. On-chain rewards use wallet claim on the staking page.'),
                ]);
            }
        }

        // Manual claim is allowed only inside the daily window. (cron path is not scheduled anymore.)
        if (! $fromCron && ! $this->isWindowOpen()) {
            throw ValidationException::withMessages([
                'claim' => __('Claim is open only :window. Next window opens :when.', [
                    'window' => $this->windowLabel(),
                    'when' => $this->nextWindowOpenAt()->format('d M Y, h:i A'),
                ]),
            ]);
        }

        return DB::transaction(function () use ($user, $fromCron, $ignoreCooldown) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->is_blocked) {
                throw ValidationException::withMessages([
                    'claim' => __('Account is blocked.'),
                ]);
            }

            if (! $ignoreCooldown) {
                $next = $this->nextClaimAt($locked);
                if ($next !== null && now()->lt($next)) {
                    throw ValidationException::withMessages([
                        'claim' => __('Next claim available after :when.', [
                            'when' => $next->copy()->timezone($this->windowTimezone())->format('d M Y, h:i A'),
                        ]),
                    ]);
                }
            }

            $stakes = Investment::query()
                ->where('user_id', $locked->id)
                ->whereIn('status', [
                    Investment::STATUS_ACTIVE,
                    Investment::STATUS_COMPLETED,
                    Investment::STATUS_UNLOCKING,
                    Investment::STATUS_UNLOCKED,
                ])
                ->where('accrued_reward_usd', '>', 0)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($stakes->isEmpty()) {
                throw ValidationException::withMessages([
                    'claim' => __('Nothing to claim. Rewards accrue on the platform schedule.'),
                ]);
            }

            $totalClaim = '0.0000';
            foreach ($stakes as $stake) {
                $chunk = number_format((float) $stake->accrued_reward_usd, 4, '.', '');
                if (bccomp($chunk, '0', 4) <= 0) {
                    continue;
                }

                $this->ledger->record(
                    $locked,
                    LedgerEntry::TYPE_INCOME_CLAIM,
                    $chunk,
                    'investment',
                    $stake->id,
                    [
                        'source' => $fromCron ? 'cron_income_auto_claim' : 'platform_income_claim',
                        'from_cron' => $fromCron,
                        'admin_fee_usd' => '0.00',
                        'note' => $fromCron
                            ? 'Accrued ROI auto-claimed by cron to virtual income — no Admin Fee'
                            : 'Accrued ROI claimed to virtual income — no Admin Fee',
                    ],
                );

                $stake->forceFill(['accrued_reward_usd' => '0.0000'])->save();
                $totalClaim = bcadd($totalClaim, $chunk, 4);

                // Level-1 ROI sharing is paid only now, on the direct's claim. A failure here must
                // never block the claim; the unshared amount is retried on the next claim.
                try {
                    app(AffiliateNetworkRoiService::class)->onRoiClaimed($locked, $chunk, (int) $stake->id);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            if (bccomp($totalClaim, '0', 4) <= 0) {
                throw ValidationException::withMessages([
                    'claim' => __('Nothing to claim.'),
                ]);
            }

            $locked->forceFill(['last_income_claim_at' => now()])->save();

            $wallet = app(WalletBalanceService::class)->virtualIncomeBalance($locked);

            return [
                'claimed_usd' => number_format((float) $totalClaim, 4, '.', ''),
                'virtual_balance_usd' => $wallet,
            ];
        });
    }
}
