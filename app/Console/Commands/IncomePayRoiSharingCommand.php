<?php

namespace App\Console\Commands;

use App\Services\Income\AffiliateNetworkRoiService;
use Illuminate\Console\Command;

class IncomePayRoiSharingCommand extends Command
{
    protected $signature = 'income:pay-roi-sharing';

    protected $description = 'Pay Level-1 ROI sharing (10% of flagged unshared Laravel ROI). Does not claim the direct’s ROI.';

    public function handle(AffiliateNetworkRoiService $sharing): int
    {
        $result = $sharing->releaseDueShares();

        $this->info(
            "roi-sharing done stakes={$result['stakes']} paid={$result['paid']} fail={$result['fail']}"
        );

        return self::SUCCESS;
    }
}
