<?php

namespace App\Http\Controllers;

use App\Models\LedgerEntry;
use App\Services\Blockchain\OnChainLevelIncomeRows;
use App\Services\Income\CompoundTransactionRows;
use App\Services\Income\IncomeClaimTransactionRows;
use App\Support\IncomeCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $incomeKey = $request->string('income')->toString();
        if ($incomeKey === '') {
            $incomeKey = null;
        }

        $query = LedgerEntry::query()
            ->production()
            ->where('user_id', $user->id)
            // Accrual is audit-only; take-home shows as income_claim after claim.
            ->where('entry_type', '!=', LedgerEntry::TYPE_ROI_ACCRUAL);

        if ($incomeKey) {
            IncomeCatalog::applyIncomeKeyFilter($query, $incomeKey);
        }

        $dateParam = $request->string('date')->toString();
        $showAllDates = $dateParam === 'all' || $dateParam === '';
        $filterDate = null;
        if (! $showAllDates) {
            try {
                $filterDate = Carbon::parse($dateParam)->startOfDay();
            } catch (\Throwable) {
                $showAllDates = true;
            }
            if (! $showAllDates && $filterDate) {
                $query->whereDate('created_at', $filterDate);
            }
        }

        $dayTotalUsd = null;
        if (! $showAllDates && $filterDate) {
            $daySum = (float) ((clone $query)->takeHomeCredits()->sum('amount_usd') ?? 0);
            if ($incomeKey === null || $incomeKey === 'participation_rewards') {
                $daySum += app(IncomeClaimTransactionRows::class)->postedSumOnDate((int) $user->id, $filterDate);
            }
            $dayTotalUsd = number_format($daySum, 2, '.', '');
        }

        $entries = IncomeCatalog::formatLedgerRows(
            $query
                ->latest('id')
                ->limit(200)
                ->get([
                    'id',
                    'entry_type',
                    'amount_usd',
                    'balance_after_usd',
                    'reference_type',
                    'reference_id',
                    'meta',
                    'created_at',
                ]),
        );

        $entries = collect($entries);

        $onChainLevel = app(OnChainLevelIncomeRows::class);
        $includeOnChainLevel = $incomeKey === null || $incomeKey === 'community_referrals';
        $raceIncome = ['race' => '0.0000', 'usdt' => '0.00'];
        if ($includeOnChainLevel) {
            $entries = $entries->concat($onChainLevel->forUser($user, $showAllDates ? null : $filterDate));
            $raceIncome = $onChainLevel->totalsForUser($user);
        }

        $includeStakingWallet = $incomeKey === null || $incomeKey === 'participation_rewards';
        if ($includeStakingWallet) {
            $onDate = $showAllDates ? null : $filterDate;
            $entries = $entries
                ->concat(app(IncomeClaimTransactionRows::class)->forUser($user, $onDate))
                ->concat(app(CompoundTransactionRows::class)->forUser($user, $onDate));
        }

        $entries = $entries
            ->sortByDesc(static fn (array $row) => (string) $row['created_at'])
            ->take(200)
            ->values()
            ->all();

        $filterLabel = null;
        if ($incomeKey) {
            foreach (IncomeCatalog::items() as $item) {
                if (($item['key'] ?? '') === $incomeKey) {
                    $filterLabel = $item['label'];
                    break;
                }
            }
        }

        $totalEarningsUsd = IncomeCatalog::totalIncomeUsd($user->id);

        $filteredEarningsUsd = null;
        if ($incomeKey) {
            $scoped = IncomeCatalog::totalsForIncomeKey($user->id, $incomeKey);
            $filteredEarningsUsd = number_format($scoped['sum'], 2, '.', '');
        }

        $today = Carbon::today();

        return Inertia::render('Transactions', [
            'entries' => $entries,
            'filter_income_key' => $incomeKey,
            'filter_label' => $filterLabel,
            'total_earnings_usd' => $totalEarningsUsd,
            'filtered_earnings_usd' => $filteredEarningsUsd,
            'filter_date' => $showAllDates ? 'all' : ($filterDate?->toDateString() ?? $today->toDateString()),
            'filter_date_label' => $showAllDates
                ? 'All dates'
                : ($filterDate?->isSameDay($today) ? 'Today' : $filterDate?->format('D, M j, Y')),
            'max_date' => $today->toDateString(),
            'day_total_usd' => $dayTotalUsd,
            'race_level_income' => $raceIncome,
        ]);
    }
}
