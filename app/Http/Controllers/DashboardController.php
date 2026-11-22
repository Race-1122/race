<?php

namespace App\Http\Controllers;

use App\Support\MemberCode;
use App\Models\BlockchainEngineStake;
use App\Models\BlockchainEvent;
use App\Models\IcoPurchase;
use App\Models\Investment;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use App\Services\Income\CompoundTransactionRows;
use App\Services\Income\RaceCoinService;
use App\Services\Income\ReferralTree;
use App\Services\Income\WalletBalanceService;
use App\Support\IncomeCatalog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * @param  array<string, float|int>  $bucket
     */
    private function pctChange(array $bucket, string $key): string
    {
        $current = (float) ($bucket[$key.'_current'] ?? 0);
        $previous = (float) ($bucket[$key.'_prev'] ?? 0);
        if ($previous <= 0) {
            return $current > 0 ? '+100.00%' : '+0.00%';
        }

        $pct = (($current - $previous) / $previous) * 100;
        $sign = $pct >= 0 ? '+' : '';

        return $sign.number_format($pct, 2, '.', '').'%';
    }

    /** keccak256("RewardPaid(address,uint256,uint256,uint256,uint256,uint256)") — Engine / WalletRaceStake claim event. */
    private const REWARD_PAID_TOPIC = '0x6a1c9812f6d42eaf5c00058b4fc1d00233e24805a4965009d650401f50adc53e';

    private function hexToDecimal(string $hex): string
    {
        $hex = ltrim(strtolower($hex), '0');
        if ($hex === '') {
            return '0';
        }
        $dec = '0';
        foreach (str_split($hex) as $ch) {
            $dec = bcadd(bcmul($dec, '16'), (string) hexdec($ch));
        }

        return $dec;
    }

    /**
     * Daily staking claims of this member (on-chain RewardPaid events + RACE minted for remaining stakes):
     * coins, value at claim time, value at today's rate, plus today's totals.
     *
     * @return array<string, mixed>
     */
    private function dailyClaimsForUser(User $user, RaceCoinService $raceCoin): array
    {
        $price = $raceCoin->priceUsd();
        $rate = number_format($price, 8, '.', '');
        $wallet = strtolower(trim((string) $user->wallet_address));
        $wei = '1000000000000000000';

        $events = BlockchainEvent::query()
            ->whereIn('event_name', ['RewardClaimed', 'RewardPaid'])
            ->where(function ($q) use ($user, $wallet) {
                $q->where('user_id', $user->id);
                if ($wallet !== '') {
                    $q->orWhere('wallet_address', $wallet);
                }
            })
            ->orderByDesc('id')
            ->get();

        $items = [];
        $totalRace = '0';
        $totalClaimUsd = '0';
        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            if (strtolower((string) ($payload['topics'][0] ?? '')) !== self::REWARD_PAID_TOPIC) {
                continue;
            }
            $data = (string) ($payload['data'] ?? '');
            $hex = strtolower(str_starts_with($data, '0x') ? substr($data, 2) : $data);
            if (strlen($hex) < 320) {
                continue;
            }
            // data: stakeIndex, rewardUsdt, rewardRace, daysPaid, racePriceUsdt
            $stakeIndex = $this->hexToDecimal(substr($hex, 0, 64));
            $rewardUsdt = bcdiv($this->hexToDecimal(substr($hex, 64, 64)), $wei, 6);
            $rewardRace = bcdiv($this->hexToDecimal(substr($hex, 128, 64)), $wei, 6);
            $days = $this->hexToDecimal(substr($hex, 192, 64));
            $valueNow = bcmul($rewardRace, $rate, 4);

            $totalRace = bcadd($totalRace, $rewardRace, 6);
            $totalClaimUsd = bcadd($totalClaimUsd, $rewardUsdt, 6);

            $when = $event->block_time ?? $event->created_at;
            $items[] = [
                'id' => 'e'.$event->id,
                'source' => 'onchain',
                'claimed_at' => $when?->toIso8601String(),
                '_ts' => $when?->getTimestamp() ?? 0,
                'stake_index' => (int) $stakeIndex,
                'days_paid' => (int) $days,
                'race' => $rewardRace,
                'value_at_claim_usd' => $rewardUsdt,
                'value_now_usd' => $valueNow,
                'tx_hash' => $event->tx_hash,
            ];
        }

        // Remaining-stake claims paid by RaceCoin.mint straight to the member wallet.
        $mintClaims = \App\Models\StakeMintClaim::query()
            ->where('user_id', $user->id)
            ->where('status', \App\Models\StakeMintClaim::STATUS_COMPLETED)
            ->orderByDesc('id')
            ->get();
        foreach ($mintClaims as $m) {
            $race = bcadd((string) $m->race_amount, '0', 6);
            $usdt = bcadd((string) $m->usdt_amount, '0', 6);
            $totalRace = bcadd($totalRace, $race, 6);
            $totalClaimUsd = bcadd($totalClaimUsd, $usdt, 6);
            $items[] = [
                'id' => 'm'.$m->id,
                'source' => 'mint',
                'claimed_at' => $m->created_at?->toIso8601String(),
                '_ts' => $m->created_at?->getTimestamp() ?? 0,
                'stake_index' => (int) $m->stake_index,
                'days_paid' => (int) $m->days,
                'race' => $race,
                'value_at_claim_usd' => $usdt,
                'value_now_usd' => bcmul($race, $rate, 4),
                'tx_hash' => $m->tx_hash,
            ];
        }

        usort($items, static fn (array $a, array $b): int => $b['_ts'] <=> $a['_ts']);

        // Dashboard list = IST today only. Lifetime totals stay on dedicated pages / header.
        $tz = $this->claimTimezone();
        $todayKey = now($tz)->toDateString();
        $todayRace = '0';
        $todayClaimUsd = '0';
        $todayCount = 0;
        $todayItems = [];
        foreach ($items as $it) {
            if ($it['_ts'] > 0 && Carbon::createFromTimestamp($it['_ts'], $tz)->toDateString() === $todayKey) {
                $todayRace = bcadd($todayRace, $it['race'], 6);
                $todayClaimUsd = bcadd($todayClaimUsd, $it['value_at_claim_usd'], 6);
                $todayCount++;
                $todayItems[] = $it;
            }
        }
        $lifetimeCount = count($items);
        $items = array_map(static function (array $it): array {
            unset($it['_ts']);

            return $it;
        }, $todayItems);

        [$todayStart, $todayEnd] = $this->todayWindowInClaimTz();
        $compounds = app(CompoundTransactionRows::class)->dashboardItems($user, $todayStart, $todayEnd);

        return [
            'items' => array_values($items),
            'compounds' => $compounds,
            'count' => $lifetimeCount,
            'total_race' => $totalRace,
            'total_value_now_usd' => bcmul($totalRace, $rate, 4),
            'total_value_claim_usd' => $totalClaimUsd,
            'rate_usd' => $rate,
            'today_label' => now($tz)->format('d M Y'),
            'today_count' => $todayCount,
            'today_race' => $todayRace,
            'today_value_now_usd' => bcmul($todayRace, $rate, 4),
            'today_value_claim_usd' => $todayClaimUsd,
        ];
    }

    private function claimTimezone(): string
    {
        return (string) config('income.claim.timezone', 'Asia/Kolkata');
    }

    /**
     * Inclusive IST calendar day, converted to app timezone for TIMESTAMP queries.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function todayWindowInClaimTz(): array
    {
        $tz = $this->claimTimezone();
        $appTz = (string) config('app.timezone', 'UTC');

        return [
            now($tz)->startOfDay()->timezone($appTz),
            now($tz)->endOfDay()->timezone($appTz),
        ];
    }

    public function index(Request $request, ReferralTree $referralTree, WalletBalanceService $wallets, RaceCoinService $raceCoin): Response
    {
        $user = $request->user()->loadMissing('userWallet');
        $uid = $user->id;
        // Unified income total (Daily claim + Other) — no Virtual/On-chain labels.
        $incomeBuckets = $wallets->incomeBuckets($user);
        $walletBalanceUsd = (float) $incomeBuckets['total_usd'];
        $raceCoinBalance = $raceCoin->currentBalance($user);
        $stakeAutoClaimLive = \App\Models\SiteSetting::stakeMintClaimEnabled();
        $compoundSvc = app(\App\Services\Income\VirtualIncomeStakeCompoundService::class);
        $compoundUsd = $compoundSvc->availableUsd($user);
        $compoundStake = $compoundSvc->bestActiveStake($user);

        // Total Team on dashboard: unlimited depth (same as Total Team page). Income rules stay L1–L10.
        $descendantIds = $referralTree->referralDescendantIds($uid, null);
        $teamSize = count($descendantIds);
        $directReferrals = User::query()->where('referred_by', $uid)->count();
        $totalTeamBusinessUsd = $referralTree->downlineInvestmentVolumeUsd($uid, true);

        $creditGroups = LedgerEntry::query()
            ->production()
            ->where('user_id', $uid)
            ->takeHomeCredits()
            ->selectRaw('entry_type, SUM(amount_usd) as total, COUNT(*) as cnt')
            ->groupBy('entry_type')
            ->get();

        $lifetimeCredits = 0.0;
        $totalIncomeUsd = 0.0;
        $incomePayoutCount = 0;
        $incomeByType = [];
        foreach ($creditGroups as $row) {
            $amount = (float) $row->total;
            $lifetimeCredits += $amount;
            $totalIncomeUsd += $amount;
            $incomePayoutCount += (int) $row->cnt;
            $incomeByType[$row->entry_type] = number_format($amount, 2, '.', '');
        }

        $planIncome = IncomeCatalog::summarizeForUser($uid);

        [$todayStart, $todayEnd] = $this->todayWindowInClaimTz();
        $incomeEntries = LedgerEntry::query()
            ->production()
            ->where('user_id', $uid)
            ->takeHomeCredits()
            ->whereBetween('created_at', [$todayStart, $todayEnd])
            ->latest('id')
            ->get([
                'id',
                'entry_type',
                'amount_usd',
                'balance_after_usd',
                'reference_type',
                'reference_id',
                'created_at',
            ])
            ->map(static fn ($row) => [
                'id' => $row->id,
                'income_name' => IncomeCatalog::labelForType((string) $row->entry_type),
                'entry_type' => $row->entry_type,
                'amount_usd' => $row->amount_usd,
                'balance_after_usd' => $row->balance_after_usd,
                'reference_type' => $row->reference_type,
                'reference_id' => $row->reference_id,
                'created_at' => $row->created_at,
            ])
            ->values();

        $investmentAgg = Investment::query()
            ->where('user_id', $uid)
            ->selectRaw(
                'COALESCE(SUM(amount_usd), 0) as principal,
                 COALESCE(SUM(CASE WHEN status = ? THEN amount_usd ELSE 0 END), 0) as active_principal,
                 COALESCE(SUM(total_roi_paid_usd), 0) as roi_paid,
                 COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as active_count',
                [Investment::STATUS_ACTIVE, Investment::STATUS_ACTIVE]
            )
            ->first();

        $principalInvested = (string) ($investmentAgg->principal ?? '0');
        $activePrincipalInvested = (string) ($investmentAgg->active_principal ?? '0');
        $roiPaidOnBook = (string) ($investmentAgg->roi_paid ?? '0');
        $activeInvestments = (int) ($investmentAgg->active_count ?? 0);

        // Active package = stake-time locked USDT principal only (never live Pancake/RACE price).
        // Keyed by users.id ($uid). member_number is display-only.
        if (Schema::hasTable('blockchain_engine_stakes')) {
            $openEngineStakes = BlockchainEngineStake::query()
                ->where('user_id', $uid)
                ->where('withdrawn', false)
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhere('status', '!=', BlockchainEngineStake::STATUS_WITHDRAWN);
                });

            $enginePrincipal = (float) (clone $openEngineStakes)->sum('principal_usdt');
            $engineCount = (int) (clone $openEngineStakes)->count();

            if ($enginePrincipal > (float) $activePrincipalInvested) {
                $activePrincipalInvested = number_format($enginePrincipal, 2, '.', '');
            }
            if ($enginePrincipal > (float) $principalInvested) {
                $principalInvested = number_format($enginePrincipal, 2, '.', '');
            }
            if ($engineCount > $activeInvestments) {
                $activeInvestments = $engineCount;
            }
        }

        $today = Carbon::now()->startOfDay();
        $todayEnd = Carbon::now()->endOfDay();

        $todayAgg = LedgerEntry::query()
            ->production()
            ->where('user_id', $uid)
            ->takeHomeCredits()
            ->whereBetween('created_at', [$today, $todayEnd])
            ->selectRaw('COALESCE(SUM(amount_usd), 0) as total, COUNT(*) as cnt')
            ->first();

        $todayIncomeUsd = (float) ($todayAgg->total ?? 0);
        $todayIncomeCount = (int) ($todayAgg->cnt ?? 0);

        $currentStart = $today->copy()->subDays(29);
        $previousStart = $today->copy()->subDays(59);

        $bucket = [
            'total_current' => 0.0,
            'total_prev' => 0.0,
            'direct_current' => 0.0,
            'direct_prev' => 0.0,
            'matching_current' => 0.0,
            'matching_prev' => 0.0,
            'roi_current' => 0.0,
            'roi_prev' => 0.0,
            'other_current' => 0.0,
            'other_prev' => 0.0,
        ];

        $windowRows = LedgerEntry::query()
            ->production()
            ->where('user_id', $uid)
            ->takeHomeCredits()
            ->where('created_at', '>=', $previousStart)
            ->get(['entry_type', 'amount_usd', 'created_at']);

        foreach ($windowRows as $row) {
            $amount = (float) $row->amount_usd;
            $inCurrent = $row->created_at?->gte($currentStart);
            $suffix = $inCurrent ? '_current' : '_prev';

            $bucket['total'.$suffix] += $amount;
            if ($row->entry_type === LedgerEntry::TYPE_REFERRAL_DIRECT) {
                $bucket['direct'.$suffix] += $amount;
            } elseif (in_array($row->entry_type, [
                LedgerEntry::TYPE_COMMUNITY_TEAM_REWARD,
                LedgerEntry::TYPE_COMMUNITY_LEADERSHIP,
                LedgerEntry::TYPE_COMMUNITY_LEADERSHIP_SKIP,
                LedgerEntry::TYPE_COMMUNITY_LEADERSHIP_RANK11_MONTHLY,
                LedgerEntry::TYPE_COMMUNITY_REFERRAL,
                LedgerEntry::TYPE_AFFILIATE_TEAM_REWARD,
                LedgerEntry::TYPE_AFFILIATE_MTH,
                LedgerEntry::TYPE_AFFILIATE_SPONSOR,
                LedgerEntry::TYPE_AFFILIATE_R10,
                LedgerEntry::TYPE_AFFILIATE_R10_LEADERSHIP,
                LedgerEntry::TYPE_ACTIVATION_REFERRAL,
                LedgerEntry::TYPE_AFFILIATE_PLACEMENT,
                LedgerEntry::TYPE_AFFILIATE_REFERRAL,
                LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
                LedgerEntry::TYPE_BONZA_BUSTER,
            ], true)) {
                $bucket['matching'.$suffix] += $amount;
            } elseif (in_array($row->entry_type, [
                LedgerEntry::TYPE_ROI_MONTHLY,
                LedgerEntry::TYPE_ROI_DAILY,
                LedgerEntry::TYPE_INCOME_CLAIM,
            ], true)) {
                $bucket['roi'.$suffix] += $amount;
            } else {
                $bucket['other'.$suffix] += $amount;
            }
        }

        $recentLedger = LedgerEntry::query()
            ->production()
            ->where('user_id', $uid)
            ->where('entry_type', '!=', LedgerEntry::TYPE_ROI_ACCRUAL)
            ->latest('id')
            ->limit(5)
            ->get([
                'id',
                'entry_type',
                'amount_usd',
                'balance_after_usd',
                'created_at',
            ]);

        $monthlyMap = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $key = $d->toDateString();
            $monthlyMap[$key] = [
                'date' => $key,
                'label' => $d->format('d M'),
                'total_usd' => 0.0,
            ];
        }

        foreach ($windowRows as $row) {
            $key = $row->created_at?->toDateString();
            if ($key && isset($monthlyMap[$key])) {
                $monthlyMap[$key]['total_usd'] += (float) $row->amount_usd;
            }
        }

        $teamMap = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $key = $d->toDateString();
            $teamMap[$key] = [
                'date' => $key,
                'label' => $d->format('d M'),
                'joins' => 0,
            ];
        }

        if (! empty($descendantIds)) {
            $teamRows = User::query()
                ->whereIn('id', $descendantIds)
                ->where('created_at', '>=', $currentStart)
                ->get(['created_at']);

            foreach ($teamRows as $row) {
                $key = $row->created_at?->toDateString();
                if (! $key || ! isset($teamMap[$key])) {
                    continue;
                }
                $teamMap[$key]['joins']++;
            }
        }

        $directNodes = User::query()
            ->where('referred_by', $uid)
            ->latest('id')
            ->limit(2)
            ->get(['id', 'name', 'member_number']);

        $secondLevel = [];
        foreach ($directNodes as $node) {
            $kids = User::query()
                ->where('referred_by', $node->id)
                ->latest('id')
                ->limit(2)
                ->get(['id', 'name', 'member_number']);
            foreach ($kids as $kid) {
                $secondLevel[] = $kid;
            }
        }

        $communityMatchingUsd = (float) ($incomeByType[LedgerEntry::TYPE_COMMUNITY_TEAM_REWARD] ?? 0)
            + (float) ($incomeByType[LedgerEntry::TYPE_COMMUNITY_LEADERSHIP] ?? 0)
            + (float) ($incomeByType[LedgerEntry::TYPE_COMMUNITY_LEADERSHIP_SKIP] ?? 0)
            + (float) ($incomeByType[LedgerEntry::TYPE_COMMUNITY_LEADERSHIP_RANK11_MONTHLY] ?? 0)
            + (float) ($incomeByType[LedgerEntry::TYPE_COMMUNITY_REFERRAL] ?? 0);

        $heldIcoCount = 0;
        $heldIcoRace = '0.0000';
        if (Schema::hasTable('ico_purchases')) {
            $wallet = strtolower(trim((string) $user->wallet_address));
            $heldQuery = IcoPurchase::query()
                ->where(function ($inner) use ($uid, $wallet) {
                    $inner->where('user_id', $uid);
                    if ($wallet !== '') {
                        $inner->orWhere('wallet_address', $wallet);
                    }
                })
                ->where('status', 'held');
            $heldIcoCount = (int) (clone $heldQuery)->count();
            $heldIcoRace = number_format((float) (clone $heldQuery)->sum('race_amount'), 4, '.', '');
        }

        return Inertia::render('Dashboard', [
            'stake_auto_claim_live' => $stakeAutoClaimLive,
            'held_ico' => [
                'count' => $heldIcoCount,
                'race' => $heldIcoRace,
            ],
            'virtual_compound' => [
                'available_usd' => $compoundUsd,
                'can_compound' => bccomp($compoundUsd, '0.01', 4) >= 0 && $compoundStake !== null,
                'stake_index' => $compoundStake['index'] ?? null,
            ],
            'summary' => [
                'balance_usd' => number_format($walletBalanceUsd, 2, '.', ''),
                'income_buckets' => $incomeBuckets,
                'race_coin_balance' => $raceCoinBalance,
                'race_coin_price_usd' => $raceCoin->formattedPriceUsd(4),
                'lifetime_credits_usd' => number_format((float) $lifetimeCredits, 2, '.', ''),
                'total_income_usd' => number_format((float) $totalIncomeUsd, 2, '.', ''),
                'today_income_usd' => number_format($todayIncomeUsd, 2, '.', ''),
                'today_income_count' => $todayIncomeCount,
                'today_label' => $today->format('M j, Y'),
                'plan_income' => $planIncome,
                'daily_claims' => $this->dailyClaimsForUser($user, $raceCoin),
                'income_entries' => $incomeEntries,
                'income_payout_count' => $incomePayoutCount,
                'principal_invested_usd' => number_format((float) $principalInvested, 2, '.', ''),
                // Locked at stake time — do not reinterpret with race_coin_price_usd / Pancake.
                'active_principal_usd' => number_format((float) $activePrincipalInvested, 2, '.', ''),
                'active_package_locked' => true,
                'roi_paid_usd' => number_format((float) $roiPaidOnBook, 2, '.', ''),
                'active_investments' => $activeInvestments,
                'income_by_type' => $incomeByType,
                'recent_ledger' => $recentLedger,
                'direct_referrals' => $directReferrals,
                'team_size' => $teamSize,
                'total_team_business_usd' => $totalTeamBusinessUsd,
                'income_pct' => [
                    'total' => $this->pctChange($bucket, 'total'),
                    'direct' => $this->pctChange($bucket, 'direct'),
                    'matching' => $this->pctChange($bucket, 'matching'),
                    'roi' => $this->pctChange($bucket, 'roi'),
                    'other' => $this->pctChange($bucket, 'other'),
                ],
                'commission_breakdown' => [
                    ['label' => 'Community', 'amount_usd' => number_format($communityMatchingUsd, 2, '.', ''), 'color' => '#3B82F6'],
                    ['label' => 'Direct', 'amount_usd' => number_format((float) ($incomeByType[LedgerEntry::TYPE_REFERRAL_DIRECT] ?? 0), 2, '.', ''), 'color' => '#8B5CF6'],
                    ['label' => 'Participation', 'amount_usd' => number_format((float) ($incomeByType[LedgerEntry::TYPE_ROI_MONTHLY] ?? 0) + (float) ($incomeByType[LedgerEntry::TYPE_ROI_DAILY] ?? 0) + (float) ($incomeByType[LedgerEntry::TYPE_INCOME_CLAIM] ?? 0), 2, '.', ''), 'color' => '#10B981'],
                    ['label' => 'Other', 'amount_usd' => number_format((float) max(0, (float) $totalIncomeUsd - $communityMatchingUsd - (float) ($incomeByType[LedgerEntry::TYPE_REFERRAL_DIRECT] ?? 0) - (float) ($incomeByType[LedgerEntry::TYPE_ROI_MONTHLY] ?? 0) - (float) ($incomeByType[LedgerEntry::TYPE_ROI_DAILY] ?? 0) - (float) ($incomeByType[LedgerEntry::TYPE_INCOME_CLAIM] ?? 0)), 2, '.', ''), 'color' => '#EAB308'],
                ],
                'monthly_income_series' => array_values(array_map(static fn ($row) => [
                    'date' => $row['date'],
                    'label' => $row['label'],
                    'total_usd' => number_format((float) $row['total_usd'], 2, '.', ''),
                ], $monthlyMap)),
                'team_growth_series' => array_values($teamMap),
                'network_preview' => [
                    'direct_nodes' => $directNodes->map(static fn ($u) => [
                        'name' => $u->name,
                        'member_code' => MemberCode::format($u->member_number),
                    ])->values()->all(),
                    'second_level_nodes' => collect($secondLevel)->take(4)->map(static fn ($u) => [
                        'name' => $u->name,
                        'member_code' => MemberCode::format($u->member_number),
                    ])->values()->all(),
                    'direct_team' => $directReferrals,
                    'total_team' => $teamSize,
                ],
            ],
        ]);
    }
}
