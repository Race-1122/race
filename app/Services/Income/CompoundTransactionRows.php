<?php

namespace App\Services\Income;

use App\Models\IncomeWalletTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Virtual-income compounds (dashboard Compound button) formatted like ledger rows.
 * These live on income_wallet_transactions, not ledger_entries.
 */
class CompoundTransactionRows
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user, ?Carbon $onDate = null, int $limit = 200): array
    {
        $query = $this->baseQuery($user);
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
        $query = $this->baseQuery($user)->whereBetween('created_at', [$from, $to]);

        return $this->mapRows($query->latest('id')->limit($limit)->get());
    }

    /**
     * Compact rows for the dashboard staking daily-claims list.
     *
     * @return list<array<string, mixed>>
     */
    public function dashboardItems(User $user, Carbon $from, Carbon $to, int $limit = 50): array
    {
        return array_map(static function (array $row): array {
            $created = $row['created_at'];
            if ($created instanceof Carbon) {
                $iso = $created->toIso8601String();
            } elseif ($created instanceof \DateTimeInterface) {
                $iso = Carbon::instance($created)->toIso8601String();
            } else {
                $iso = $created ? Carbon::parse((string) $created)->toIso8601String() : null;
            }

            return [
                'id' => $row['id'],
                'claimed_at' => $iso,
                'stake_index' => $row['stake_index'],
                'amount_usd' => $row['amount_usd'],
                'race' => $row['amount_race'],
                'type' => $row['entry_type'],
                'detail' => $row['detail'],
            ];
        }, $this->forUserBetween($user, $from, $to, $limit));
    }

    private function baseQuery(User $user): Builder
    {
        return IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->whereIn('type', [
                IncomeWalletTransaction::TYPE_COMPOUND,
                IncomeWalletTransaction::TYPE_COMPOUND_REFUND,
            ])
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
        $isRefund = $row->type === IncomeWalletTransaction::TYPE_COMPOUND_REFUND;
        $signedUsd = $row->direction === IncomeWalletTransaction::DIRECTION_DEBIT
            ? bcmul((string) $row->amount, '-1', 4)
            : bcadd((string) $row->amount, '0', 4);

        $detail = $isRefund ? 'Compound refund' : 'Compounded into stake';
        if ($stake > 0) {
            $detail .= ' #'.$stake;
        }

        return [
            'id' => 'compound-'.$row->id,
            'entry_type' => $row->type,
            'income_name' => $isRefund ? 'Compound refund' : 'Compound',
            'income_key' => 'participation_rewards',
            'amount_usd' => $signedUsd,
            'amount_race' => $race,
            'balance_after_usd' => $row->balance_after_usd,
            'balance_before_usd' => $row->balance_before_usd,
            'reference_type' => $row->source_reference,
            'reference_id' => $stake ?: null,
            'level' => null,
            'level_label' => $isRefund ? 'Refund' : 'Compound',
            'detail' => $detail,
            'created_at' => $row->created_at,
            'stake_index' => $stake,
            'is_compound' => true,
        ];
    }
}
