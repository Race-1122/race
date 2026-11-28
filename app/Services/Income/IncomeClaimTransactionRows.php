<?php

namespace App\Services\Income;

use App\Models\IncomeWalletTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Daily staking auto-claims live on income_wallet_transactions (type income_claim),
 * not ledger_entries. Format them like ledger rows for Staking Reward All/Today.
 */
class IncomeClaimTransactionRows
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, ?Carbon $onDate = null, int $limit = 200): array
    {
        $query = $this->baseQuery($user->id);
        if ($onDate) {
            $query->whereDate('created_at', $onDate->toDateString());
        }

        return $this->mapRows($query->latest('id')->limit($limit)->get());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forUserBetween(User $user, Carbon $from, Carbon $to, int $limit = 200): array
    {
        return $this->mapRows(
            $this->baseQuery($user->id)
                ->whereBetween('created_at', [$from, $to])
                ->latest('id')
                ->limit($limit)
                ->get(),
        );
    }

    /**
     * @return array{sum: float, count: int}
     */
    public function postedTotals(int $userId): array
    {
        $query = $this->baseQuery($userId)->where('direction', IncomeWalletTransaction::DIRECTION_CREDIT);

        return [
            'sum' => (float) ($query->sum('amount') ?? 0),
            'count' => (int) (clone $query)->count(),
        ];
    }

    public function postedSumOnDate(int $userId, Carbon $onDate): float
    {
        return (float) ($this->baseQuery($userId)
            ->where('direction', IncomeWalletTransaction::DIRECTION_CREDIT)
            ->whereDate('created_at', $onDate->toDateString())
            ->sum('amount') ?? 0);
    }

    public function postedSumBetween(int $userId, Carbon $from, Carbon $to): float
    {
        return (float) ($this->baseQuery($userId)
            ->where('direction', IncomeWalletTransaction::DIRECTION_CREDIT)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount') ?? 0);
    }

    private function baseQuery(int $userId): Builder
    {
        return IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('type', IncomeWalletTransaction::TYPE_INCOME_CLAIM)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, IncomeWalletTransaction>  $rows
     * @return list<array<string, mixed>>
     */
    private function mapRows($rows): array
    {
        return $rows->map(fn (IncomeWalletTransaction $row) => $this->format($row))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function format(IncomeWalletTransaction $row): array
    {
        $meta = is_array($row->metadata) ? $row->metadata : [];
        $stake = (int) ($meta['stake_index'] ?? 0);
        $race = isset($meta['race_amount']) ? (string) $meta['race_amount'] : null;
        $signedUsd = $row->direction === IncomeWalletTransaction::DIRECTION_DEBIT
            ? bcmul((string) $row->amount, '-1', 4)
            : bcadd((string) $row->amount, '0', 4);

        $detail = ! empty($meta['from_cron'])
            ? 'Daily auto-claim → Virtual Income'
            : 'Staking reward claim';
        if ($stake > 0) {
            $detail .= ' · Stake #'.$stake;
        }

        return [
            'id' => 'iwt-claim-'.$row->id,
            'entry_type' => IncomeWalletTransaction::TYPE_INCOME_CLAIM,
            'income_name' => 'Staking Reward Rates',
            'income_key' => 'participation_rewards',
            'asset' => $race ? 'RACE' : 'USDT',
            'amount_usd' => $signedUsd,
            'amount_race' => $race,
            'balance_after_usd' => $row->balance_after_usd,
            'balance_before_usd' => $row->balance_before_usd,
            'reference_type' => $row->source_reference,
            'reference_id' => $stake ?: null,
            'level' => null,
            'level_label' => ! empty($meta['from_cron']) ? 'Daily' : null,
            'detail' => $detail,
            'created_at' => $row->created_at,
            'stake_index' => $stake,
        ];
    }
}
