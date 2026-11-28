<?php

namespace App\Services\Income;

use App\Models\BlockchainEngineStake;
use App\Models\IncomeWalletTransaction;
use App\Models\User;
use App\Models\VirtualStakeCompound;
use App\Services\Blockchain\CommunityEngineRpcReader;
use App\Services\Blockchain\RaceCoinDirectMintService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Compound virtual income onto the last fixed-lock stake (not Flexible).
 * Stays internal until that stake is withdrawn on-chain, then RaceCoin.mint to wallet.
 * No Engine/WalletRace redeploy.
 */
class VirtualIncomeStakeCompoundService
{
    public function __construct(
        protected VirtualIncomeWalletService $virtualWallet,
        protected WalletBalanceService $wallets,
        protected RaceCoinService $raceCoin,
        protected RaceCoinDirectMintService $mint,
    ) {}

    public function availableUsd(User $user): string
    {
        return number_format((float) $this->wallets->virtualIncomeBalance($user), 4, '.', '');
    }

    public function extraPrincipalUsd(User $user, int $stakeIndex): string
    {
        if (! Schema::hasTable('virtual_stake_compounds')) {
            return '0.00000000';
        }
        $sum = $this->pendingCompoundQuery($user->id, $stakeIndex)->sum('usdt_amount');

        return number_format((float) $sum, 8, '.', '');
    }

    /**
     * Sum of pending overlay compound USDT on open Engine stakes (Dashboard Active package).
     */
    public function totalExtraPrincipalUsd(User $user): string
    {
        if (! Schema::hasTable('virtual_stake_compounds') || ! Schema::hasTable('blockchain_engine_stakes')) {
            return '0.00';
        }

        $openIndexes = BlockchainEngineStake::query()
            ->where('user_id', $user->id)
            ->where('withdrawn', false)
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhere('status', '!=', BlockchainEngineStake::STATUS_WITHDRAWN);
            })
            ->pluck('stake_index')
            ->map(static fn ($i) => (int) $i)
            ->unique()
            ->values()
            ->all();

        if ($openIndexes === []) {
            return '0.00';
        }

        $query = VirtualStakeCompound::query()
            ->where('user_id', $user->id)
            ->whereIn('stake_index', $openIndexes);
        if (Schema::hasColumn('virtual_stake_compounds', 'status')) {
            $query->where(function ($q) {
                $q->where('status', VirtualStakeCompound::STATUS_PENDING)
                    ->orWhereNull('status');
            });
        }

        return number_format((float) ($query->sum('usdt_amount') ?? 0), 2, '.', '');
    }

    public function extraRace(User $user, int $stakeIndex): string
    {
        if (! Schema::hasTable('virtual_stake_compounds')) {
            return '0.00000000';
        }
        $sum = $this->pendingCompoundQuery($user->id, $stakeIndex)->sum('race_amount');

        return number_format((float) $sum, 8, '.', '');
    }

    /**
     * Indexed Engine stakes with overlay compound principal/RACE folded in for UI.
     *
     * @param  list<array<string, mixed>>  $stakes
     * @return list<array<string, mixed>>
     */
    public function withExtraPrincipal(User $user, array $stakes): array
    {
        return array_map(function (array $stake) use ($user): array {
            $index = (int) ($stake['stake_index'] ?? 0);
            $extraUsd = $this->extraPrincipalUsd($user, $index);
            $extraRace = $this->extraRace($user, $index);
            if (bccomp($extraUsd, '0', 8) > 0) {
                $stake['principal_usdt'] = bcadd((string) ($stake['principal_usdt'] ?? '0'), $extraUsd, 8);
            }
            if (bccomp($extraRace, '0', 8) > 0) {
                $stake['staked_race'] = bcadd((string) ($stake['staked_race'] ?? '0'), $extraRace, 8);
            }

            return $stake;
        }, $stakes);
    }

    /**
     * Compound events for the Staking page table (same columns as Engine stakes).
     *
     * @return list<array<string, mixed>>
     */
    public function stakingTableRows(User $user): array
    {
        if (! Schema::hasTable('virtual_stake_compounds')) {
            return [];
        }

        $lockByIndex = BlockchainEngineStake::query()
            ->where('user_id', $user->id)
            ->get(['stake_index', 'lock_seconds'])
            ->keyBy('stake_index');

        return VirtualStakeCompound::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(static function (VirtualStakeCompound $row) use ($lockByIndex): array {
                $lockSeconds = (int) ($lockByIndex->get((int) $row->stake_index)?->lock_seconds ?? 0);

                return [
                    'kind' => 'compound',
                    'id' => 'compound-'.$row->id,
                    'stake_index' => (int) $row->stake_index,
                    'principal_usdt' => number_format((float) $row->usdt_amount, 4, '.', ''),
                    'staked_race' => (string) $row->race_amount,
                    'plan_label' => 'Compound',
                    'lock_days' => $lockSeconds > 0 ? (int) round($lockSeconds / 86400) : 0,
                    'status' => ((string) ($row->status ?? VirtualStakeCompound::STATUS_PENDING)) === VirtualStakeCompound::STATUS_MINTED
                        ? 'Minted'
                        : 'Compound',
                    'withdrawn' => false,
                    'tx_hash' => (string) ($row->mint_tx_hash ?: ''),
                    'created_at' => $row->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Attach virtual USD to the last stake (internal). Never mints to the member wallet.
     *
     * @return array{
     *     usdt: string,
     *     race: string,
     *     stake_index: int,
     *     lock_seconds: int,
     *     mint_tx_hash: string,
     *     compound_tx_hash: string,
     *     transaction_id: int,
     *     stake_contract: string,
     *     race_token: string,
     *     wallet_address: string
     * }
     */
    public function compoundAll(User $user, ?string $amountUsd = null): array
    {
        $available = $this->availableUsd($user);
        if (bccomp($available, '0.01', 4) < 0) {
            throw ValidationException::withMessages([
                'compound' => __('No virtual income to compound.'),
            ]);
        }

        $amount = $amountUsd !== null && trim($amountUsd) !== ''
            ? number_format((float) $amountUsd, 4, '.', '')
            : $available;
        if (bccomp($amount, '0.01', 4) < 0) {
            throw ValidationException::withMessages([
                'compound' => __('Compound amount must be at least $0.01.'),
            ]);
        }
        if (bccomp($amount, $available, 4) > 0) {
            throw ValidationException::withMessages([
                'compound' => __('Compound amount cannot exceed your virtual balance (:usd).', [
                    'usd' => $available,
                ]),
            ]);
        }

        $wallet = strtolower(trim((string) ($user->wallet_address ?? '')));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            throw ValidationException::withMessages([
                'compound' => __('Connect a BEP-20 wallet before compounding.'),
            ]);
        }

        $target = $this->bestActiveStake($user);
        if ($target === null) {
            throw ValidationException::withMessages([
                'compound' => __('You need an active fixed-lock stake to compound into. Flexible stakes cannot be compounded.'),
            ]);
        }
        if ((int) ($target['lock_seconds'] ?? 0) <= 0) {
            throw ValidationException::withMessages([
                'compound' => __('Compounding is only allowed on fixed-lock stakes, not Flexible.'),
            ]);
        }

        $price = number_format((float) $this->raceCoin->priceUsd(), 8, '.', '');
        if (bccomp($price, '0', 8) <= 0) {
            $price = (string) ($target['price'] ?? '0');
        }
        $race = bccomp($price, '0', 8) > 0 ? bcdiv($amount, $price, 8) : '0.00000000';

        $lock = Cache::lock('virtual-stake-compound:'.$user->id, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'compound' => __('A compound is already in progress. Please wait.'),
            ]);
        }

        try {
            $row = DB::transaction(function () use ($user, $amount, $target, $price, $race) {
                $key = 'virtual_stake_compound:'.$user->id.':'.Str::uuid()->toString();

                $debit = $this->virtualWallet->debit(
                    $user,
                    IncomeWalletTransaction::TYPE_COMPOUND,
                    $amount,
                    'engine_stake:'.$target['index'],
                    $key,
                    [
                        'bucket' => VirtualIncomeWalletService::BUCKET_DAILY_CLAIM,
                        'stake_index' => $target['index'],
                        'lock_seconds' => $target['lock_seconds'],
                        'source_type' => $target['source_type'],
                        'phase' => 'virtual_into_last_stake',
                        'on_chain_required' => false,
                        'race_amount' => $race,
                        'race_price_usdt' => $price,
                        'user_wallet_credited' => false,
                    ],
                );

                if (Schema::hasTable('virtual_stake_compounds')) {
                    $payload = [
                        'user_id' => $user->id,
                        'stake_index' => $target['index'],
                        'usdt_amount' => $amount,
                        'race_amount' => $race,
                        'race_price_usdt' => $price,
                        'income_wallet_transaction_id' => $debit->id,
                    ];
                    if (Schema::hasColumn('virtual_stake_compounds', 'status')) {
                        $payload['status'] = VirtualStakeCompound::STATUS_PENDING;
                    }
                    VirtualStakeCompound::query()->create($payload);
                }

                return $debit;
            });

            return [
                'usdt' => $amount,
                'race' => $race,
                'stake_index' => $target['index'],
                'lock_seconds' => $target['lock_seconds'],
                'mint_tx_hash' => '',
                'compound_tx_hash' => '',
                'transaction_id' => $row->id,
                'stake_contract' => $target['stake_contract'],
                'race_token' => strtolower(trim((string) config('blockchain.contracts.race_token', ''))),
                'wallet_address' => $wallet,
            ];
        } finally {
            $lock->release();
        }
    }

    public function confirmStakeTx(User $user, int $transactionId, string $txHash): IncomeWalletTransaction
    {
        $txHash = strtolower(trim($txHash));
        if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            throw ValidationException::withMessages([
                'tx_hash' => __('Invalid transaction hash.'),
            ]);
        }

        return DB::transaction(function () use ($user, $transactionId, $txHash) {
            $row = IncomeWalletTransaction::query()
                ->where('user_id', $user->id)
                ->where('id', $transactionId)
                ->where('type', IncomeWalletTransaction::TYPE_COMPOUND)
                ->lockForUpdate()
                ->firstOrFail();

            $meta = is_array($row->metadata) ? $row->metadata : [];
            if (($meta['phase'] ?? '') === 'confirmed_on_chain' && ($meta['stake_tx_hash'] ?? '') === $txHash) {
                return $row;
            }

            $meta['stake_tx_hash'] = $txHash;
            $meta['phase'] = 'confirmed_on_chain';
            $row->forceFill(['metadata' => $meta])->save();

            return $row;
        });
    }

    /**
     * Silent refund: overlay compounds that never minted / never staked on-chain.
     *
     * @return array{refunded: int, skipped: int, usd: string}
     */
    public function refundFailedOverlays(bool $dryRun = false): array
    {
        $rows = IncomeWalletTransaction::query()
            ->where('type', IncomeWalletTransaction::TYPE_COMPOUND)
            ->where('direction', IncomeWalletTransaction::DIRECTION_DEBIT)
            ->where('status', IncomeWalletTransaction::STATUS_POSTED)
            ->orderBy('id')
            ->get();

        $refunded = 0;
        $skipped = 0;
        $usd = '0.0000';

        foreach ($rows as $row) {
            $meta = is_array($row->metadata) ? $row->metadata : [];
            $phase = (string) ($meta['phase'] ?? '');
            $onChainRequired = $meta['on_chain_required'] ?? true;
            $mintHash = strtolower(trim((string) ($meta['mint_tx_hash'] ?? '')));

            $isOverlayOnly = $phase === 'compounded_to_stake'
                && ($onChainRequired === false || $onChainRequired === 'false' || $onChainRequired === 0)
                && $mintHash === '';

            if (! $isOverlayOnly) {
                $skipped++;

                continue;
            }

            $refundKey = 'failed_overlay_refund:iwt:'.$row->id;
            $existing = IncomeWalletTransaction::query()
                ->where('user_id', $row->user_id)
                ->where('idempotency_key', $refundKey)
                ->first();
            if ($existing || $phase === 'refunded') {
                $skipped++;

                continue;
            }

            if ($dryRun) {
                $refunded++;
                $usd = bcadd($usd, (string) $row->amount, 4);

                continue;
            }

            $user = User::query()->find($row->user_id);
            if (! $user) {
                $skipped++;

                continue;
            }

            $this->virtualWallet->credit(
                $user,
                IncomeWalletTransaction::TYPE_COMPOUND_REFUND,
                (string) $row->amount,
                $row->source_reference,
                $refundKey,
                [
                    'compound_debit_id' => $row->id,
                    'refund_reason' => 'overlay_without_onchain_mint',
                    'silent' => true,
                ],
            );

            $meta['phase'] = 'refunded';
            $meta['refund_reason'] = 'overlay_without_onchain_mint';
            $row->forceFill(['metadata' => $meta])->save();

            if (Schema::hasTable('virtual_stake_compounds')) {
                VirtualStakeCompound::query()
                    ->where('income_wallet_transaction_id', $row->id)
                    ->delete();
            }

            $refunded++;
            $usd = bcadd($usd, (string) $row->amount, 4);
        }

        return [
            'refunded' => $refunded,
            'skipped' => $skipped,
            'usd' => $usd,
        ];
    }

    /**
     * After the Engine stake is withdrawn, mint pending overlay RACE to the member wallet.
     *
     * @return array{minted: int, race: string, tx_hash: string}
     */
    public function mintCompoundedOnWithdraw(User $user, int $stakeIndex): array
    {
        $empty = ['minted' => 0, 'race' => '0', 'tx_hash' => ''];
        if (! Schema::hasTable('virtual_stake_compounds') || $stakeIndex < 0) {
            return $empty;
        }

        $lock = Cache::lock('virtual-compound-mint:'.$user->id.':'.$stakeIndex, 120);
        if (! $lock->get()) {
            return $empty;
        }

        try {
            [$ids, $race] = DB::transaction(function () use ($user, $stakeIndex) {
                $rows = $this->pendingCompoundQuery($user->id, $stakeIndex)->orderBy('id')->lockForUpdate()->get();
                $total = '0';
                foreach ($rows as $row) {
                    $total = bcadd($total, (string) $row->race_amount, 8);
                }

                return [$rows->pluck('id')->all(), $total];
            });
            if ($ids === [] || bccomp($race, '0', 8) <= 0) {
                return $empty;
            }

            if (! $this->mint->configured()) {
                throw new \RuntimeException('RaceCoin minter is not configured.');
            }

            $wallet = strtolower(trim((string) ($user->wallet_address ?? '')));
            $minted = $this->mint->mintToWallet($wallet, $race);
            $tx = strtolower((string) ($minted['tx_hash'] ?? ''));

            $now = now();
            VirtualStakeCompound::query()
                ->whereIn('id', $ids)
                ->update([
                    'status' => VirtualStakeCompound::STATUS_MINTED,
                    'mint_tx_hash' => $tx,
                    'minted_at' => $now,
                ]);

            return [
                'minted' => count($ids),
                'race' => $race,
                'tx_hash' => $tx,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Retry mint for any withdrawn Engine stake that still has pending overlay compounds.
     *
     * @return array{users: int, minted: int, race: string}
     */
    public function settleWithdrawnCompounds(): array
    {
        $users = 0;
        $minted = 0;
        $race = '0';
        if (! Schema::hasTable('virtual_stake_compounds') || ! Schema::hasTable('blockchain_engine_stakes')) {
            return ['users' => 0, 'minted' => 0, 'race' => '0'];
        }

        $pending = VirtualStakeCompound::query()
            ->select('user_id', 'stake_index')
            ->when(Schema::hasColumn('virtual_stake_compounds', 'status'), function ($q) {
                $q->where(function ($inner) {
                    $inner->where('status', VirtualStakeCompound::STATUS_PENDING)
                        ->orWhereNull('status');
                });
            })
            ->groupBy('user_id', 'stake_index')
            ->get();

        foreach ($pending as $row) {
            $withdrawn = BlockchainEngineStake::query()
                ->where('user_id', $row->user_id)
                ->where('stake_index', $row->stake_index)
                ->where('withdrawn', true)
                ->exists();
            if (! $withdrawn) {
                continue;
            }
            $user = User::query()->find($row->user_id);
            if (! $user) {
                continue;
            }
            try {
                $out = $this->mintCompoundedOnWithdraw($user, (int) $row->stake_index);
            } catch (\Throwable $e) {
                Log::warning('virtual compound mint on withdraw failed', [
                    'user_id' => $user->id,
                    'stake_index' => $row->stake_index,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }
            if ((int) $out['minted'] > 0) {
                $users++;
                $minted += (int) $out['minted'];
                $race = bcadd($race, (string) $out['race'], 8);
            }
        }

        return ['users' => $users, 'minted' => $minted, 'race' => $race];
    }

    /**
     * Last active fixed-lock stake (most recently opened). Flexible is skipped.
     *
     *
     * @return array{
     *     index: int,
     *     price: string,
     *     lock_seconds: int,
     *     lock_label: string,
     *     source_type: string,
     *     source_label: string,
     *     principal_usd: string,
     *     stake_contract: string
     * }|null
     */
    public function bestActiveStake(User $user): ?array
    {
        $row = BlockchainEngineStake::query()
            ->where('user_id', $user->id)
            ->where('withdrawn', false)
            ->where('lock_seconds', '>', 0)
            ->orderByDesc('started_at')
            ->orderByDesc('stake_index')
            ->first();

        if ($row) {
            $price = (string) ($row->stake_price_usdt ?: '0');
            $source = (string) ($row->source_type ?: BlockchainEngineStake::SOURCE_ICO);
            $contract = strtolower(trim((string) ($row->contract_address ?: '')));
            if ($contract === '') {
                $contract = $this->contractForSource($source);
            }
            $lockSeconds = (int) ($row->lock_seconds ?? 0);

            $index = (int) $row->stake_index;

            return [
                'index' => $index,
                'price' => bccomp($price, '0', 18) > 0 ? $price : '0',
                'lock_seconds' => $lockSeconds,
                'lock_label' => $this->lockLabel($lockSeconds),
                'source_type' => $source,
                'source_label' => $this->sourceLabel($source),
                'principal_usd' => $this->displayPrincipalUsd((string) ($row->principal_usdt ?? '0'), $user, $index),
                'stake_contract' => $contract,
            ];
        }

        $wallet = strtolower(trim((string) ($user->wallet_address ?? '')));
        $reader = CommunityEngineRpcReader::fromConfig();
        if ($wallet === '' || $reader === null) {
            return null;
        }
        $count = $reader->readStakeCount($wallet);
        if ($count === null || $count < 1) {
            return null;
        }

        for ($i = $count - 1; $i >= 0; $i--) {
            $last = $reader->readStakeAt($wallet, $i);
            if ($last === null || ! empty($last['withdrawn'])) {
                continue;
            }
            $lockSeconds = (int) ($last['lock_seconds'] ?? 0);
            if ($lockSeconds <= 0) {
                continue;
            }

            $source = BlockchainEngineStake::SOURCE_PARTICIPATION;

            return [
                'index' => $i,
                'price' => (string) ($last['reward_price_usdt'] ?? '0'),
                'lock_seconds' => $lockSeconds,
                'lock_label' => $this->lockLabel($lockSeconds),
                'source_type' => $source,
                'source_label' => $this->sourceLabel($source),
                'principal_usd' => $this->displayPrincipalUsd((string) ($last['principal_usdt'] ?? '0'), $user, $i),
                'stake_contract' => $this->contractForSource($source),
            ];
        }

        return null;
    }

    private function pendingCompoundQuery(int $userId, int $stakeIndex)
    {
        $query = VirtualStakeCompound::query()
            ->where('user_id', $userId)
            ->where('stake_index', $stakeIndex);
        if (Schema::hasColumn('virtual_stake_compounds', 'status')) {
            $query->where(function ($q) {
                $q->where('status', VirtualStakeCompound::STATUS_PENDING)
                    ->orWhereNull('status');
            });
        }

        return $query;
    }

    private function lockLabel(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'Flexible';
        }

        return ((int) round($seconds / 86400)).' days';
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            BlockchainEngineStake::SOURCE_ICO => 'ICO stake',
            BlockchainEngineStake::SOURCE_WALLET_RACE => 'Wallet RACE stake',
            default => 'USDT stake',
        };
    }

    private function contractForSource(string $source): string
    {
        if ($source === BlockchainEngineStake::SOURCE_WALLET_RACE) {
            return strtolower(trim((string) config('blockchain.contracts.wallet_race_stake', '')));
        }

        return strtolower(trim((string) config('blockchain.contracts.community_engine', '')));
    }

    private function displayPrincipalUsd(string $base, User $user, int $stakeIndex): string
    {
        $total = bcadd($base, $this->extraPrincipalUsd($user, $stakeIndex), 8);

        return number_format((float) $total, 2, '.', '');
    }
}
