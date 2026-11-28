<?php

namespace App\Console\Commands;

use App\Services\Income\VirtualIncomeStakeCompoundService;
use Illuminate\Console\Command;

class IncomeSettleWithdrawnVirtualCompoundsCommand extends Command
{
    protected $signature = 'income:settle-withdrawn-virtual-compounds';

    protected $description = 'Mint pending virtual compounds after the matching Engine stake is withdrawn.';

    public function handle(VirtualIncomeStakeCompoundService $compound): int
    {
        $out = $compound->settleWithdrawnCompounds();
        $this->info('users='.$out['users'].' minted='.$out['minted'].' race='.$out['race']);

        return self::SUCCESS;
    }
}
