<?php

namespace App\Console\Commands;

use App\Services\Blockchain\BlockchainEngineStakeIndexer;
use Illuminate\Console\Command;

/**
 * Repair: members who already staked (ICO / wallet RACE) but ID still pending.
 */
class MembersActivateStakedIdsCommand extends Command
{
    protected $signature = 'members:activate-staked-ids
        {--dry-run : Count only, do not write}
        {--reindex-wallet-stakes : Also re-ingest StakeOpened events into engine stakes}';

    protected $description = 'Activate Member ID / participation for users who already have an open on-chain stake';

    public function handle(BlockchainEngineStakeIndexer $indexer): int
    {
        if ($this->option('reindex-wallet-stakes')) {
            $n = $indexer->backfillFromBlockchainEvents();
            $this->info("reindexed stake events={$n}");
        }

        if ($this->option('dry-run')) {
            $this->warn('dry-run: listing pending only (no writes)');

            $stakerIds = \App\Models\BlockchainEngineStake::query()
                ->whereNotNull('user_id')
                ->where(function ($q) {
                    $q->where('withdrawn', false)->orWhereNull('withdrawn');
                })
                ->distinct()
                ->pluck('user_id');

            $pending = \App\Models\User::query()
                ->whereIn('id', $stakerIds)
                ->where(function ($q) {
                    $q->whereNull('participation_activated_at')
                        ->orWhereNull('id_activated_at');
                })
                ->get(['id', 'member_number', 'wallet_address', 'participation_activated_at', 'id_activated_at']);

            foreach ($pending as $u) {
                $this->line("pending user={$u->id} member={$u->member_number} wallet={$u->wallet_address}");
            }
            $this->info('pending_count='.$pending->count().' stakers='.$stakerIds->count());

            return self::SUCCESS;
        }

        $result = $indexer->activateMembersWithOpenStakes();
        $this->info("scanned_stakers={$result['scanned']} newly_activated={$result['activated']}");

        return self::SUCCESS;
    }
}
