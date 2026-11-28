<?php

namespace App\Services\Admin;

use App\Models\BlockchainEngineStake;
use App\Models\IcoPurchase;
use App\Models\IncomeWalletTransaction;
use App\Models\Investment;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Income\WalletBalanceService;
use App\Support\IncomeCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class UserDossierService
{
    /**
     * A–Z admin dossier for one member (read-only).
     *
     * @return array<string, mixed>
     */
    public function summarize(User $user): array
    {
        $uid = (int) $user->id;
        $wallet = strtolower(trim((string) $user->wallet_address));

        $investmentTotals = $this->investmentTotals($uid);
        $engineTotals = $this->engineStakeTotals($uid, $wallet);
        $icoTotals = $this->icoTotals($uid, $wallet);
        $depositTotals = $this->depositTotals($uid);
        $withdrawalTotals = $this->withdrawalTotals($uid);
        $income = $this->incomeTotals($uid);
        $virtual = '0.00';
        try {
            $virtual = app(WalletBalanceService::class)->virtualIncomeBalance($user);
        } catch (\Throwable) {
            // table may be missing in some test envs
        }

        $teamFeePaid = (float) $withdrawalTotals['team_reward_usd'] > 0.009;

        return [
            'registered_at' => $this->formatWhen($user->created_at),
            'activated_at' => $this->formatWhen($user->id_activated_at, 'Not activated'),
            'participation_at' => $this->formatWhen($user->participation_activated_at),
            'last_income_claim_at' => $this->formatWhen($user->last_income_claim_at, 'Never'),
            'wallet_address' => (string) ($user->wallet_address ?? ''),
            'member_code' => (string) $user->member_code,
            'blocked' => (bool) $user->is_blocked,
            'laravel_invested_usd' => $investmentTotals['total_usd'],
            'laravel_active_usd' => $investmentTotals['active_usd'],
            'laravel_stake_count' => $investmentTotals['count'],
            'laravel_first_stake_at' => $investmentTotals['first_at'],
            'laravel_last_stake_at' => $investmentTotals['last_at'],
            'engine_principal_usd' => $engineTotals['principal_usd'],
            'engine_active_usd' => $engineTotals['active_usd'],
            'engine_stake_count' => $engineTotals['count'],
            'engine_staked_race' => $engineTotals['staked_race'],
            'ico_buy_count' => $icoTotals['count'],
            'ico_held_count' => $icoTotals['held_count'],
            'ico_staked_count' => $icoTotals['staked_count'],
            'ico_usdt_usd' => $icoTotals['usdt_usd'],
            'ico_race' => $icoTotals['race'],
            'deposit_count' => $depositTotals['count'],
            'deposit_usd' => $depositTotals['usd'],
            'petrol_count' => $withdrawalTotals['count'],
            'petrol_completed_count' => $withdrawalTotals['completed_count'],
            'petrol_gross_usd' => $withdrawalTotals['gross_usd'],
            'petrol_net_usd' => $withdrawalTotals['net_usd'],
            'team_10_percent_usd' => $withdrawalTotals['team_reward_usd'],
            'admin_fee_usd' => $withdrawalTotals['admin_fee_usd'],
            'team_10_percent_split' => $teamFeePaid ? 'Yes — 10% team reward deducted' : 'No — no 10% team split on record',
            'income_count' => $income['count'],
            'income_usd' => $income['usd'],
            'income_by_type' => $income['by_type'],
            'income_from' => $income['from'],
            'virtual_income_usd' => number_format((float) $virtual, 2, '.', ''),
            'wallet_balance_usd' => number_format((float) ($user->balance_usd ?? 0), 2, '.', ''),
        ];
    }

    /**
     * @return array{total_usd: string, active_usd: string, count: int, first_at: string, last_at: string}
     */
    private function investmentTotals(int $userId): array
    {
        $empty = [
            'total_usd' => '0.00',
            'active_usd' => '0.00',
            'count' => 0,
            'first_at' => '—',
            'last_at' => '—',
        ];
        if (! Schema::hasTable('investments')) {
            return $empty;
        }

        $rows = Investment::query()->where('user_id', $userId)->get(['amount_usd', 'status', 'created_at']);
        $total = 0.0;
        $active = 0.0;
        foreach ($rows as $row) {
            $amt = (float) $row->amount_usd;
            $total += $amt;
            if ($row->status === Investment::STATUS_ACTIVE) {
                $active += $amt;
            }
        }

        return [
            'total_usd' => number_format($total, 2, '.', ''),
            'active_usd' => number_format($active, 2, '.', ''),
            'count' => $rows->count(),
            'first_at' => $this->formatWhen($rows->min('created_at')),
            'last_at' => $this->formatWhen($rows->max('created_at')),
        ];
    }

    /**
     * @return array{principal_usd: string, active_usd: string, count: int, staked_race: string}
     */
    private function engineStakeTotals(int $userId, string $wallet): array
    {
        $empty = [
            'principal_usd' => '0.00',
            'active_usd' => '0.00',
            'count' => 0,
            'staked_race' => '0.0000',
        ];
        if (! Schema::hasTable('blockchain_engine_stakes')) {
            return $empty;
        }

        $q = BlockchainEngineStake::query()->where(function ($inner) use ($userId, $wallet): void {
            $inner->where('user_id', $userId);
            if ($wallet !== '') {
                $inner->orWhereRaw('LOWER(wallet_address) = ?', [$wallet]);
            }
        });
        $rows = $q->get(['principal_usdt', 'staked_race', 'withdrawn', 'status']);
        $principal = 0.0;
        $active = 0.0;
        $race = 0.0;
        foreach ($rows as $row) {
            $p = (float) $row->principal_usdt;
            $principal += $p;
            $race += (float) $row->staked_race;
            $open = ! $row->withdrawn && $row->status !== BlockchainEngineStake::STATUS_WITHDRAWN;
            if ($open) {
                $active += $p;
            }
        }

        return [
            'principal_usd' => number_format($principal, 2, '.', ''),
            'active_usd' => number_format($active, 2, '.', ''),
            'count' => $rows->count(),
            'staked_race' => number_format($race, 4, '.', ''),
        ];
    }

    /**
     * @return array{count: int, held_count: int, staked_count: int, usdt_usd: string, race: string}
     */
    private function icoTotals(int $userId, string $wallet): array
    {
        $empty = [
            'count' => 0,
            'held_count' => 0,
            'staked_count' => 0,
            'usdt_usd' => '0.00',
            'race' => '0.0000',
        ];
        if (! Schema::hasTable('ico_purchases')) {
            return $empty;
        }

        $rows = IcoPurchase::query()
            ->where(function ($inner) use ($userId, $wallet): void {
                $inner->where('user_id', $userId);
                if ($wallet !== '') {
                    $inner->orWhereRaw('LOWER(wallet_address) = ?', [$wallet]);
                }
            })
            ->get(['status', 'usdt_amount', 'race_amount']);

        $usdt = 0.0;
        $race = 0.0;
        $held = 0;
        $staked = 0;
        foreach ($rows as $row) {
            $usdt += (float) $row->usdt_amount;
            $race += (float) $row->race_amount;
            $status = strtolower((string) $row->status);
            if ($status === 'staked') {
                $staked++;
            } else {
                $held++;
            }
        }

        return [
            'count' => $rows->count(),
            'held_count' => $held,
            'staked_count' => $staked,
            'usdt_usd' => number_format($usdt, 2, '.', ''),
            'race' => number_format($race, 4, '.', ''),
        ];
    }

    /**
     * @return array{count: int, usd: string}
     */
    private function depositTotals(int $userId): array
    {
        if (! Schema::hasTable('ledger_entries')) {
            return ['count' => 0, 'usd' => '0.00'];
        }

        $q = LedgerEntry::query()
            ->where('user_id', $userId)
            ->where('entry_type', LedgerEntry::TYPE_WALLET_DEPOSIT);

        return [
            'count' => (int) (clone $q)->count(),
            'usd' => number_format((float) (clone $q)->sum('amount_usd'), 2, '.', ''),
        ];
    }

    /**
     * @return array{count: int, completed_count: int, gross_usd: string, net_usd: string, team_reward_usd: string, admin_fee_usd: string}
     */
    private function withdrawalTotals(int $userId): array
    {
        $empty = [
            'count' => 0,
            'completed_count' => 0,
            'gross_usd' => '0.00',
            'net_usd' => '0.00',
            'team_reward_usd' => '0.00',
            'admin_fee_usd' => '0.00',
        ];
        if (! Schema::hasTable('withdrawals')) {
            return $empty;
        }

        $rows = Withdrawal::query()->where('user_id', $userId)->get();
        $gross = 0.0;
        $net = 0.0;
        $team = 0.0;
        $admin = 0.0;
        $completed = 0;
        foreach ($rows as $row) {
            if ($row->status !== Withdrawal::STATUS_COMPLETED && $row->status !== Withdrawal::STATUS_PENDING) {
                continue;
            }
            if ($row->status === Withdrawal::STATUS_COMPLETED) {
                $completed++;
            }
            $gross += (float) $row->amount_usd;
            $net += (float) $row->net_usd;
            $team += (float) $row->teamRewardUsd();
            $admin += (float) $row->adminFeeUsd();
        }

        return [
            'count' => $rows->count(),
            'completed_count' => $completed,
            'gross_usd' => number_format($gross, 2, '.', ''),
            'net_usd' => number_format($net, 2, '.', ''),
            'team_reward_usd' => number_format($team, 2, '.', ''),
            'admin_fee_usd' => number_format($admin, 2, '.', ''),
        ];
    }

    /**
     * @return array{count: int, usd: string, by_type: list<array{type: string, label: string, count: int, usd: string}>, from: list<array{user_id: int, member: string, count: int, usd: string}>}
     */
    private function incomeTotals(int $userId): array
    {
        $empty = ['count' => 0, 'usd' => '0.00', 'by_type' => [], 'from' => []];
        if (! Schema::hasTable('ledger_entries')) {
            return $empty;
        }

        $types = [];
        foreach (IncomeCatalog::items() as $item) {
            foreach ($item['types'] as $type) {
                $types[] = $type;
            }
        }
        $types[] = LedgerEntry::TYPE_REFERRAL_DIRECT;
        $types[] = LedgerEntry::TYPE_ACTIVATION_REFERRAL;
        $types = array_values(array_unique($types));

        $rows = LedgerEntry::query()
            ->where('user_id', $userId)
            ->whereIn('entry_type', $types)
            ->get(['entry_type', 'amount_usd', 'meta']);

        $byType = [];
        $fromMap = [];
        $total = 0.0;
        foreach ($rows as $row) {
            $amt = (float) $row->amount_usd;
            $total += $amt;
            $type = (string) $row->entry_type;
            if (! isset($byType[$type])) {
                $byType[$type] = ['type' => $type, 'label' => IncomeCatalog::labelForType($type), 'count' => 0, 'usd' => 0.0];
            }
            $byType[$type]['count']++;
            $byType[$type]['usd'] += $amt;

            $meta = is_array($row->meta) ? $row->meta : [];
            $fromId = (int) ($meta['from_user_id'] ?? 0);
            if ($fromId > 0) {
                if (! isset($fromMap[$fromId])) {
                    $fromMap[$fromId] = ['user_id' => $fromId, 'count' => 0, 'usd' => 0.0];
                }
                $fromMap[$fromId]['count']++;
                $fromMap[$fromId]['usd'] += $amt;
            }
        }

        $fromUsers = User::query()
            ->whereIn('id', array_keys($fromMap))
            ->get(['id', 'name', 'member_number', 'wallet_address'])
            ->keyBy('id');

        $from = [];
        foreach ($fromMap as $fromId => $agg) {
            $u = $fromUsers->get($fromId);
            $from[] = [
                'user_id' => $fromId,
                'member' => $u
                    ? trim('#'.($u->member_number ?? $u->id).' '.($u->name ?? '').' '.($u->wallet_address ?? ''))
                    : 'User #'.$fromId,
                'count' => $agg['count'],
                'usd' => number_format($agg['usd'], 2, '.', ''),
            ];
        }
        usort($from, static fn ($a, $b) => (float) $b['usd'] <=> (float) $a['usd']);

        $byTypeList = array_values($byType);
        usort($byTypeList, static fn ($a, $b) => $b['usd'] <=> $a['usd']);
        foreach ($byTypeList as &$row) {
            $row['usd'] = number_format((float) $row['usd'], 2, '.', '');
        }
        unset($row);

        return [
            'count' => $rows->count(),
            'usd' => number_format($total, 2, '.', ''),
            'by_type' => $byTypeList,
            'from' => $from,
        ];
    }

    private function formatWhen(mixed $value, string $empty = '—'): string
    {
        if ($value === null || $value === '') {
            return $empty;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        try {
            return \Illuminate\Support\Carbon::parse((string) $value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $empty;
        }
    }

    /**
     * @return Collection<int, mixed>
     */
    public function latestIcoPurchases(User $user): Collection
    {
        if (! Schema::hasTable('ico_purchases')) {
            return collect();
        }
        $wallet = strtolower(trim((string) $user->wallet_address));

        return IcoPurchase::query()
            ->where(function ($inner) use ($user, $wallet): void {
                $inner->where('user_id', $user->id);
                if ($wallet !== '') {
                    $inner->orWhereRaw('LOWER(wallet_address) = ?', [$wallet]);
                }
            })
            ->latest('id')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, mixed>
     */
    public function latestEngineStakes(User $user): Collection
    {
        if (! Schema::hasTable('blockchain_engine_stakes')) {
            return collect();
        }
        $wallet = strtolower(trim((string) $user->wallet_address));

        return BlockchainEngineStake::query()
            ->where(function ($inner) use ($user, $wallet): void {
                $inner->where('user_id', $user->id);
                if ($wallet !== '') {
                    $inner->orWhereRaw('LOWER(wallet_address) = ?', [$wallet]);
                }
            })
            ->latest('id')
            ->limit(50)
            ->get();
    }

    /**
     * @return Collection<int, mixed>
     */
    public function latestIncomeWallet(User $user): Collection
    {
        if (! Schema::hasTable('income_wallet_transactions')) {
            return collect();
        }

        return IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get();
    }
}
