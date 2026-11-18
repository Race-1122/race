<?php

namespace App\Console\Commands;

use App\Models\StakeMintAdvance;
use App\Models\User;
use App\Services\Income\StakeMintClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Recoup stake ROI paid by RACE mint when the Engine later pays the same days (claim / compound / maturity).
 */
class StakeMintReconcileCommand extends Command
{
    protected $signature = 'stake-mint:reconcile {--user= : Only this user id}';

    protected $description = 'Reconcile stake mint-claim advances against Engine lastRewardAt (recoup double-paid days).';

    public function handle(StakeMintClaimService $service): int
    {
        $query = StakeMintAdvance::query()
            ->where(fn ($q) => $q->where('days_outstanding', '>', 0)->orWhere('recoup_pending_usdt', '>', 0));
        if ($this->option('user')) {
            $query->where('user_id', (int) $this->option('user'));
        }

        $ok = 0;
        $fail = 0;
        foreach ($query->distinct()->pluck('user_id') as $userId) {
            $user = User::query()->find($userId);
            if (! $user) {
                continue;
            }
            try {
                $service->reconcileUser($user);
                $ok++;
            } catch (\Throwable $e) {
                $fail++;
                Log::warning('stake-mint:reconcile failed', ['user_id' => $userId, 'message' => $e->getMessage()]);
                $this->warn("user={$userId} skip: ".$e->getMessage());
            }
        }

        $this->info("stake-mint reconcile ok={$ok} fail={$fail}");

        return self::SUCCESS;
    }
}
