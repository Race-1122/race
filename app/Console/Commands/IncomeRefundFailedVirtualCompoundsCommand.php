<?php

namespace App\Console\Commands;

use App\Services\Income\VirtualIncomeStakeCompoundService;
use Illuminate\Console\Command;

class IncomeRefundFailedVirtualCompoundsCommand extends Command
{
    protected $signature = 'income:refund-failed-virtual-compounds {--dry-run : Report only, do not write}';

    protected $description = 'Silent refund of overlay compounds that never minted RACE or staked on-chain.';

    public function handle(VirtualIncomeStakeCompoundService $compound): int
    {
        $dry = (bool) $this->option('dry-run');
        $out = $compound->refundFailedOverlays($dry);

        $this->info(sprintf(
            'Failed-overlay compound refund: %d refunded, %d skipped, $%s%s',
            $out['refunded'],
            $out['skipped'],
            $out['usd'],
            $dry ? ' (dry-run)' : '',
        ));

        return self::SUCCESS;
    }
}
