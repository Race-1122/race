<?php

namespace App\Services\Income;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\RewardPlan;
use Illuminate\Support\Facades\Log;

/**
 * ROI Sharing Framework: Level-1 (direct) only — up to 10% of a direct's staking ROI.
 * Unlimited directs. Paid at 05:00 IST (`income:pay-roi-sharing`) and also when the
 * direct claims. Never at accrual time (`income:pay-roi`). Each flagged unit is shared once.
 */
class AffiliateNetworkRoiService
{
    public function __construct(
        protected LedgerWriter $ledger,
    ) {}

    /**
     * Daily cron: pay unshared flagged ROI to L1 sponsors. Does not claim the direct's ROI.
     *
     * @return array{stakes: int, paid: int, fail: int}
     */
    public function releaseDueShares(): array
    {
        $stakes = 0;
        $paid = 0;
        $fail = 0;

        if (! RewardPlan::roiSharingEnabled()) {
            return compact('stakes', 'paid', 'fail');
        }

        $groups = LedgerEntry::query()
            ->where('entry_type', LedgerEntry::TYPE_ROI_ACCRUAL)
            ->where('reference_type', 'investment')
            ->get(['user_id', 'reference_id', 'amount_usd', 'meta'])
            ->groupBy('reference_id');

        foreach ($groups as $investmentId => $rows) {
            $flagged = '0.0000';
            $userId = null;
            foreach ($rows as $row) {
                $userId = (int) $row->user_id;
                $meta = is_array($row->meta) ? $row->meta : [];
                if (! empty($meta['roi_share_on_claim'])) {
                    $flagged = bcadd($flagged, number_format((float) $row->amount_usd, 4, '.', ''), 4);
                }
            }

            if ($userId === null || bccomp($flagged, '0', 4) <= 0) {
                continue;
            }

            $user = User::query()->find($userId);
            if (! $user || $user->is_blocked) {
                continue;
            }

            $stakes++;
            $before = LedgerEntry::query()
                ->where('entry_type', LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI)
                ->where('reference_type', 'investment')
                ->where('reference_id', (int) $investmentId)
                ->count();

            try {
                $this->onRoiClaimed($user, $flagged, (int) $investmentId);
            } catch (\Throwable $e) {
                $fail++;
                Log::warning('roi-sharing auto-release failed', [
                    'user_id' => $user->id,
                    'investment_id' => (int) $investmentId,
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            $after = LedgerEntry::query()
                ->where('entry_type', LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI)
                ->where('reference_type', 'investment')
                ->where('reference_id', (int) $investmentId)
                ->count();

            if ($after > $before) {
                $paid++;
            }
        }

        return compact('stakes', 'paid', 'fail');
    }

    /**
     * After {@see $claimer} claims accrued ROI of one stake, pay their direct sponsor (L1 only).
     *
     * Only ROI accrued by the new "share on claim" cron (roi_accrual rows flagged roi_share_on_claim)
     * is shareable, and each unit of ROI is shared at most once. Older accruals were already shared
     * at accrual time and are never shared again.
     */
    public function onRoiClaimed(User $claimer, string $claimedUsd, int $investmentId): void
    {
        if (! RewardPlan::roiSharingEnabled()) {
            return;
        }

        if (bccomp($claimedUsd, '0', 4) <= 0 || ! $claimer->referred_by) {
            return;
        }

        $sponsor = User::query()->find($claimer->referred_by);
        if (! $sponsor || $sponsor->is_blocked || $sponsor->participation_activated_at === null) {
            return;
        }

        $pct = RewardPlan::roiSharingDirectPercent();
        if ($pct <= 0) {
            return;
        }

        // ROI of this stake that is eligible for sharing on claim.
        $accrued = '0.0000';
        LedgerEntry::query()
            ->where('user_id', $claimer->id)
            ->where('entry_type', LedgerEntry::TYPE_ROI_ACCRUAL)
            ->where('reference_type', 'investment')
            ->where('reference_id', $investmentId)
            ->get(['amount_usd', 'meta'])
            ->each(function (LedgerEntry $row) use (&$accrued): void {
                $meta = is_array($row->meta) ? $row->meta : [];
                if (! empty($meta['roi_share_on_claim'])) {
                    $accrued = bcadd($accrued, number_format((float) $row->amount_usd, 4, '.', ''), 4);
                }
            });

        // ROI base already shared through earlier claims.
        $alreadyShared = '0.0000';
        LedgerEntry::query()
            ->where('user_id', $sponsor->id)
            ->where('entry_type', LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI)
            ->where('reference_type', 'investment')
            ->where('reference_id', $investmentId)
            ->get(['meta'])
            ->each(function (LedgerEntry $row) use (&$alreadyShared, $claimer): void {
                $meta = is_array($row->meta) ? $row->meta : [];
                if (($meta['share_trigger'] ?? null) === 'claim'
                    && (int) ($meta['from_user_id'] ?? 0) === (int) $claimer->id) {
                    $alreadyShared = bcadd($alreadyShared, number_format((float) ($meta['base_roi_usd'] ?? 0), 4, '.', ''), 4);
                }
            });

        $remaining = bcsub($accrued, $alreadyShared, 4);
        if (bccomp($remaining, '0', 4) <= 0) {
            return;
        }

        $claimedUsd = number_format((float) $claimedUsd, 4, '.', '');
        $base = bccomp($claimedUsd, $remaining, 4) <= 0 ? $claimedUsd : $remaining;

        $rate = bcdiv(number_format($pct, 4, '.', ''), '100', 8);
        $share = bcmul($base, $rate, 4);
        if (bccomp($share, '0', 4) <= 0) {
            return;
        }

        $this->ledger->record(
            $sponsor,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            $share,
            'investment',
            $investmentId,
            [
                'network_level' => 1,
                'percent' => $pct,
                'from_user_id' => $claimer->id,
                'source_roi_entry_type' => LedgerEntry::TYPE_INCOME_CLAIM,
                'share_trigger' => 'claim',
                'base_roi_usd' => $base,
                'framework' => 'roi_sharing',
            ],
        );
    }
}
