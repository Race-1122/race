<?php

namespace App\Services\Income;

use App\Models\IncomeWalletTransaction;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\UserWallet;
use App\Support\IncomeVaultFinancialAuthority;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative Virtual Income Wallet (USDT-notional earned income).
 * Balance = on-chain indexer when income_vault.authoritative_balance, else sum(credits) − debits (4 dp).
 */
class VirtualIncomeWalletService
{
    public const BUCKET_DAILY_CLAIM = 'daily_claim';

    public const BUCKET_OTHER = 'other';

    public function availableBalance(User $user): string
    {
        $onChain = app(\App\Services\Blockchain\OnChainIncomeReadService::class)->balanceForUser($user);
        if ($onChain !== null) {
            return $onChain;
        }

        return $this->sumBalance((int) $user->id);
    }

    /**
     * Split withdrawable balance into Daily claim vs Other (no Virtual/On-chain labels).
     * Untagged historical withdrawals reduce Other first, then Daily claim.
     *
     * @return array{
     *     total_usd: string,
     *     daily_claim_usd: string,
     *     other_usd: string,
     *     daily_claim_earned_usd: string,
     *     other_earned_usd: string
     * }
     */
    public function bucketBalances(User $user): array
    {
        $userId = (int) $user->id;
        $total = $this->availableBalance($user);

        $dailyTypes = config('virtual_income_wallet.buckets.daily_claim', [
            IncomeWalletTransaction::TYPE_INCOME_CLAIM,
            IncomeWalletTransaction::TYPE_DAILY_REWARD,
        ]);
        $otherTypes = config('virtual_income_wallet.buckets.other', [
            IncomeWalletTransaction::TYPE_LEVEL_INCOME,
            IncomeWalletTransaction::TYPE_TEAM_INCOME,
            IncomeWalletTransaction::TYPE_REFERRAL_INCOME,
            IncomeWalletTransaction::TYPE_OTHER_INCOME,
            IncomeWalletTransaction::TYPE_STAKE_UNLOCK_EMI,
            IncomeWalletTransaction::TYPE_COMPOUND_REFUND,
            IncomeWalletTransaction::TYPE_ADJUSTMENT,
        ]);

        $dailyEarned = $this->sumCreditsByTypes($userId, $dailyTypes);
        $otherEarned = $this->sumCreditsByTypes($userId, $otherTypes);

        $taggedDailyDebits = $this->sumDebitsForBucket($userId, self::BUCKET_DAILY_CLAIM);
        $taggedOtherDebits = $this->sumDebitsForBucket($userId, self::BUCKET_OTHER);
        $untaggedDebits = $this->sumUntaggedDebits($userId);

        $dailyNet = bcsub($dailyEarned, $taggedDailyDebits, 4);
        $otherNet = bcsub($otherEarned, $taggedOtherDebits, 4);
        if (bccomp($dailyNet, '0', 4) < 0) {
            $dailyNet = '0.0000';
        }
        if (bccomp($otherNet, '0', 4) < 0) {
            $otherNet = '0.0000';
        }

        // Historical / untagged debits: Other first, then Daily claim.
        $remainUntagged = $untaggedDebits;
        if (bccomp($remainUntagged, '0', 4) > 0) {
            $takeOther = bccomp($otherNet, $remainUntagged, 4) <= 0 ? $otherNet : $remainUntagged;
            $otherNet = bcsub($otherNet, $takeOther, 4);
            $remainUntagged = bcsub($remainUntagged, $takeOther, 4);
        }
        if (bccomp($remainUntagged, '0', 4) > 0) {
            $takeDaily = bccomp($dailyNet, $remainUntagged, 4) <= 0 ? $dailyNet : $remainUntagged;
            $dailyNet = bcsub($dailyNet, $takeDaily, 4);
        }

        // Clamp so daily + other == total (authoritative wallet sum).
        $sumBuckets = bcadd($dailyNet, $otherNet, 4);
        if (bccomp($sumBuckets, $total, 4) > 0) {
            $overflow = bcsub($sumBuckets, $total, 4);
            $cutOther = bccomp($otherNet, $overflow, 4) <= 0 ? $otherNet : $overflow;
            $otherNet = bcsub($otherNet, $cutOther, 4);
            $overflow = bcsub($overflow, $cutOther, 4);
            if (bccomp($overflow, '0', 4) > 0) {
                $dailyNet = bcsub($dailyNet, $overflow, 4);
                if (bccomp($dailyNet, '0', 4) < 0) {
                    $dailyNet = '0.0000';
                }
            }
        } elseif (bccomp($sumBuckets, $total, 4) < 0) {
            // Leftover total (unknown credit types) → Other.
            $otherNet = bcadd($otherNet, bcsub($total, $sumBuckets, 4), 4);
        }

        return [
            'total_usd' => number_format((float) $total, 2, '.', ''),
            'daily_claim_usd' => number_format((float) $dailyNet, 2, '.', ''),
            'other_usd' => number_format((float) $otherNet, 2, '.', ''),
            'daily_claim_earned_usd' => number_format((float) $dailyEarned, 2, '.', ''),
            'other_earned_usd' => number_format((float) $otherEarned, 2, '.', ''),
        ];
    }

    public function availableInBucket(User $user, string $bucket): string
    {
        $buckets = $this->bucketBalances($user);
        if ($bucket === self::BUCKET_DAILY_CLAIM) {
            return number_format((float) $buckets['daily_claim_usd'], 2, '.', '');
        }

        return number_format((float) $buckets['other_usd'], 2, '.', '');
    }

    /**
     * @param  list<string>  $types
     */
    private function sumCreditsByTypes(int $userId, array $types): string
    {
        if ($types === []) {
            return '0.0000';
        }

        $sum = (string) (IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->where('direction', IncomeWalletTransaction::DIRECTION_CREDIT)
            ->whereIn('type', $types)
            ->where(fn ($q) => $q->where('asset', config('virtual_income_wallet.asset_default', 'USDT'))->orWhereNull('asset'))
            ->sum('amount') ?? '0');

        return number_format((float) $sum, 4, '.', '');
    }

    private function sumDebitsForBucket(int $userId, string $bucket): string
    {
        $sum = (string) (IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->where('direction', IncomeWalletTransaction::DIRECTION_DEBIT)
            ->where(fn ($q) => $q->where('asset', config('virtual_income_wallet.asset_default', 'USDT'))->orWhereNull('asset'))
            ->where('metadata->income_bucket', $bucket)
            ->sum('amount') ?? '0');

        return number_format((float) $sum, 4, '.', '');
    }

    private function sumUntaggedDebits(int $userId): string
    {
        $sum = (string) (IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->where('direction', IncomeWalletTransaction::DIRECTION_DEBIT)
            ->where(fn ($q) => $q->where('asset', config('virtual_income_wallet.asset_default', 'USDT'))->orWhereNull('asset'))
            ->where(function ($q) {
                $q->whereNull('metadata->income_bucket')
                    ->orWhere('metadata->income_bucket', '');
            })
            ->sum('amount') ?? '0');

        return number_format((float) $sum, 4, '.', '');
    }

    public function sumBalance(int $userId): string
    {
        $credits = (string) (IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->where('direction', IncomeWalletTransaction::DIRECTION_CREDIT)
            ->where(fn ($q) => $q->where('asset', config('virtual_income_wallet.asset_default', 'USDT'))->orWhereNull('asset'))
            ->sum('amount') ?? '0');

        $debits = (string) (IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->where('direction', IncomeWalletTransaction::DIRECTION_DEBIT)
            ->where(fn ($q) => $q->where('asset', config('virtual_income_wallet.asset_default', 'USDT'))->orWhereNull('asset'))
            ->sum('amount') ?? '0');

        $bal = bcsub(
            number_format((float) $credits, 4, '.', ''),
            number_format((float) $debits, 4, '.', ''),
            4,
        );

        if (bccomp($bal, '0', 4) < 0) {
            return '0.0000';
        }

        return $bal;
    }

    /**
     * @return array{
     *     available_balance_usd: string,
     *     today_income_usd: string,
     *     total_claimed_usd: string,
     *     total_compounded_usd: string,
     *     total_withdrawn_usd: string
     * }
     */
    public function dashboardSummary(User $user): array
    {
        $userId = (int) $user->id;
        $todayStart = now()->startOfDay();

        $todayIncome = (string) (IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->where('direction', IncomeWalletTransaction::DIRECTION_CREDIT)
            ->where(fn ($q) => $q->where('asset', config('virtual_income_wallet.asset_default', 'USDT'))->orWhereNull('asset'))
            ->where('created_at', '>=', $todayStart)
            ->sum('amount') ?? '0');

        $totalClaimed = (string) (IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->where('direction', IncomeWalletTransaction::DIRECTION_CREDIT)
            ->where('type', IncomeWalletTransaction::TYPE_INCOME_CLAIM)
            ->sum('amount') ?? '0');

        $totalCompound = (string) (IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->where('direction', IncomeWalletTransaction::DIRECTION_DEBIT)
            ->where('type', IncomeWalletTransaction::TYPE_COMPOUND)
            ->sum('amount') ?? '0');

        $totalWithdrawn = (string) (IncomeWalletTransaction::query()
            ->where('user_id', $userId)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->where('direction', IncomeWalletTransaction::DIRECTION_DEBIT)
            ->where('type', IncomeWalletTransaction::TYPE_WITHDRAWAL)
            ->sum('amount') ?? '0');

        return [
            'available_balance_usd' => $this->availableBalance($user),
            'today_income_usd' => number_format((float) $todayIncome, 4, '.', ''),
            'total_claimed_usd' => number_format((float) $totalClaimed, 4, '.', ''),
            'total_compounded_usd' => number_format((float) $totalCompound, 4, '.', ''),
            'total_withdrawn_usd' => number_format((float) $totalWithdrawn, 4, '.', ''),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function credit(
        User $user,
        string $type,
        string $amountUsd,
        ?string $sourceReference = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): IncomeWalletTransaction {
        return $this->post($user, $type, $amountUsd, IncomeWalletTransaction::DIRECTION_CREDIT, $sourceReference, $idempotencyKey, $metadata);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function debit(
        User $user,
        string $type,
        string $amountUsd,
        ?string $sourceReference = null,
        ?string $idempotencyKey = null,
        ?array $metadata = null,
    ): IncomeWalletTransaction {
        return $this->post($user, $type, $amountUsd, IncomeWalletTransaction::DIRECTION_DEBIT, $sourceReference, $idempotencyKey, $metadata);
    }

    /**
     * Mirror a legacy ledger_entries row into income_wallet_transactions (idempotent via ledger id).
     */
    public function mirrorLedgerEntry(LedgerEntry $entry): ?IncomeWalletTransaction
    {
        $entryType = (string) $entry->entry_type;
        if (in_array($entryType, config('virtual_income_wallet.mirror_exclude', []), true)) {
            return null;
        }

        $mappedType = config('virtual_income_wallet.ledger_type_map')[$entryType] ?? null;
        if ($mappedType === null) {
            return null;
        }

        $amount = number_format(abs((float) $entry->amount_usd), 4, '.', '');
        if (bccomp($amount, '0', 4) <= 0) {
            return null;
        }

        $direction = bccomp((string) $entry->amount_usd, '0', 4) >= 0
            ? IncomeWalletTransaction::DIRECTION_CREDIT
            : IncomeWalletTransaction::DIRECTION_DEBIT;

        $sourceRef = $entry->reference_type && $entry->reference_id
            ? "{$entry->reference_type}:{$entry->reference_id}"
            : "ledger:{$entry->id}";

        return $this->post(
            $entry->user,
            $mappedType,
            $amount,
            $direction,
            $sourceRef,
            "ledger_entry:{$entry->id}",
            [
                'ledger_entry_id' => $entry->id,
                'ledger_entry_type' => $entryType,
                'meta' => $entry->meta,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function post(
        User $user,
        string $type,
        string $amountUsd,
        string $direction,
        ?string $sourceReference,
        ?string $idempotencyKey,
        ?array $metadata,
    ): IncomeWalletTransaction {
        $amount = number_format((float) $amountUsd, 4, '.', '');
        if (bccomp($amount, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('Amount must be greater than zero.'),
            ]);
        }

        return DB::transaction(function () use ($user, $type, $amount, $direction, $sourceReference, $idempotencyKey, $metadata) {
            if (! IncomeVaultFinancialAuthority::isReadModelMirrorWrite($metadata)) {
                IncomeVaultFinancialAuthority::assertVirtualWalletMutationAllowed();
            }

            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            UserWallet::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'balance_usd' => $user->balance_usd ?? 0,
                    'virtual_balance_usd' => '0.0000',
                ],
            );
            /** @var UserWallet $wallet */
            $wallet = UserWallet::query()->where('user_id', $user->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $existing = IncomeWalletTransaction::query()
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return $existing;
                }
            }

            $before = number_format((float) ($wallet->virtual_balance_usd ?? 0), 4, '.', '');
            if ($direction === IncomeWalletTransaction::DIRECTION_DEBIT) {
                if (bccomp($before, $amount, 4) < 0) {
                    throw ValidationException::withMessages([
                        'amount' => __('Insufficient Virtual Income Wallet balance.'),
                    ]);
                }
                $after = bcsub($before, $amount, 4);
            } else {
                $after = bcadd($before, $amount, 4);
            }

            $row = IncomeWalletTransaction::query()->create([
                'user_id' => $user->id,
                'wallet_address' => $user->wallet_address,
                'type' => $type,
                'source_reference' => $sourceReference,
                'amount' => $amount,
                'balance_before_usd' => $before,
                'balance_after_usd' => $after,
                'asset' => config('virtual_income_wallet.asset_default', 'USDT'),
                'direction' => $direction,
                'status' => IncomeWalletTransaction::STATUS_POSTED,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
            ]);

            $wallet->forceFill(['virtual_balance_usd' => $after])->save();

            return $row;
        });
    }
}
