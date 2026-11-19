<?php

namespace App\Console\Commands;

use App\Models\BlockchainEngineStake;
use App\Models\User;
use App\Services\Income\StakeMintClaimService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 04:00 IST / admin button — Engine stake ROI → Virtual Income.
 * Live Engine claimReward still needs the member wallet.
 */
class StakeMintAutoClaimCommand extends Command
{
    protected $signature = 'stake-mint:auto-claim
        {--user= : Only this user id}
        {--force : Run even if Go-live is OFF (admin one-shot)}
        {--dry-run : List users without writing}';

    protected $description = 'Credit Engine stake ROI into Virtual Income for all members.';

    public function handle(StakeMintClaimService $service): int
    {
        $force = (bool) $this->option('force');
        if (! $service->enabled() && ! $force) {
            $this->warn('Stake claim Go-live is OFF — skipped.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $userIds = collect();

        if ($this->option('user')) {
            $userIds = collect([(int) $this->option('user')]);
        } elseif (Schema::hasTable('blockchain_engine_stakes')) {
            $userIds = BlockchainEngineStake::query()
                ->whereNotNull('user_id')
                ->distinct()
                ->orderBy('user_id')
                ->pluck('user_id');
        }

        if (! $this->option('user')) {
            $extra = User::query()
                ->whereNotNull('wallet_address')
                ->where('wallet_address', '!=', '')
                ->where(function ($q) {
                    $q->whereNotNull('participation_activated_at')
                        ->orWhereHas('investments');
                })
                ->pluck('id');
            $userIds = $userIds->merge($extra)->unique()->values();
        }

        $ok = 0;
        $claims = 0;
        $usdt = '0.0000';
        $fail = 0;

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);
            if (! $user || $user->is_blocked) {
                continue;
            }

            if ($dry) {
                $this->line("dry-run user={$user->id} wallet=".($user->wallet_address ?? ''));
                $ok++;

                continue;
            }

            try {
                $result = $service->autoClaimUser($user, ['force' => $force]);
                $claims += $result['claims'];
                $usdt = bcadd($usdt, $result['usdt'], 4);
                if ($result['claims'] > 0) {
                    $ok++;
                    $this->info("user={$user->id} claims={$result['claims']} usdt={$result['usdt']}");
                }
                foreach ($result['errors'] as $err) {
                    $fail++;
                    $this->warn("user={$user->id} {$err}");
                    Log::warning('stake-mint:auto-claim', ['user_id' => $user->id, 'error' => $err]);
                }
            } catch (\Throwable $e) {
                $fail++;
                Log::warning('stake-mint:auto-claim failed', ['user_id' => $user->id, 'message' => $e->getMessage()]);
                $this->warn("user={$user->id} ".$e->getMessage());
            }
        }

        $this->info("auto-claim done users_ok={$ok} claims={$claims} usdt={$usdt} fail={$fail}");

        return self::SUCCESS;
    }
}
