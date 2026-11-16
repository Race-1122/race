<?php

namespace App\Console\Commands;

use App\Models\Investment;
use App\Models\User;
use App\Services\Income\IncomeClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Move accrued staking ROI into virtual income wallets (no Admin Fee).
 *
 * Midnight batch: credit everyone with accrued > 0 (ignores 24h manual cooldown).
 * Due-only: respect last_income_claim_at + interval (for frequent cron).
 */
class IncomeAutoClaimCommand extends Command
{
    protected $signature = 'income:auto-claim
        {--midnight-batch : Credit all users with accrued rewards (nightly sync)}
        {--due-only : Only users whose manual claim cooldown has elapsed}
        {--dry-run : List users without writing}';

    protected $description = 'Auto-claim accrued ROI into virtual income wallets (cron). Manual Claim button still works.';

    public function handle(IncomeClaimService $claims): int
    {
        if (! config('income.auto_claim.enabled', true)) {
            $this->warn('income.auto_claim.enabled is false — skipped.');

            return self::SUCCESS;
        }

        $midnight = (bool) $this->option('midnight-batch');
        $dueOnly = (bool) $this->option('due-only') || ! $midnight;
        $dry = (bool) $this->option('dry-run');

        if ($midnight) {
            $dueOnly = false;
        }

        $intervalHours = $claims->claimIntervalHours();
        $userIds = Investment::query()
            ->where('accrued_reward_usd', '>', 0)
            ->whereIn('status', [
                Investment::STATUS_ACTIVE,
                Investment::STATUS_COMPLETED,
                Investment::STATUS_UNLOCKING,
                Investment::STATUS_UNLOCKED,
            ])
            ->distinct()
            ->pluck('user_id');

        $ok = 0;
        $skip = 0;
        $fail = 0;

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);
            if (! $user || $user->is_blocked) {
                $skip++;
                continue;
            }

            if ($dueOnly) {
                $next = $claims->nextClaimAt($user);
                if ($next !== null && now()->lt($next)) {
                    $skip++;
                    continue;
                }
            }

            if ($dry) {
                $this->line("dry-run user={$user->id}");
                $ok++;
                continue;
            }

            try {
                $result = $claims->claimAccruedIncome($user, [
                    'from_cron' => true,
                    'ignore_cooldown' => $midnight,
                ]);
                $ok++;
                $this->info("user={$user->id} claimed={$result['claimed_usd']} virtual={$result['virtual_balance_usd']}");
            } catch (\Throwable $e) {
                $fail++;
                Log::warning('income:auto-claim failed', [
                    'user_id' => $user->id,
                    'message' => $e->getMessage(),
                ]);
                $this->warn("user={$user->id} skip: ".$e->getMessage());
            }
        }

        $this->info("auto-claim done ok={$ok} skip={$skip} fail={$fail} interval_h={$intervalHours}");

        return self::SUCCESS;
    }
}
