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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Compound virtual income: mint RACE to the member wallet, then the member
 * stakes those coins on-chain (same lock as their last stake). No Engine redeploy.
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
        $sum = VirtualStakeCompound::query()
            ->where('user_id', $user->id)
            ->where('stake_index', $stakeIndex)
            ->sum('usdt_amount');

        return number_format((float) $sum, 8, '.', '');
    }

    /**
     * Mint RACE first, then debit virtual. Overlay-only (old) path is gone.
     *
     * @return array{
     *     usdt: string,
     *     race: string,
     *     stake_index: int,
     *     lock_seconds: int,
     *     mint_tx_hash: string,
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
                'compound' => __('You need an active on-chain stake to compound into.'),
            ]);
        }

        if (! $this->mint->configured()) {
            throw ValidationException::withMessages([
                'compound' => __('On-chain mint is not configured. Try again later.'),
            ]);
        }

        $price = number_format((float) $this->raceCoin->priceUsd(), 8, '.', '');
        if (bccomp($price, '0', 8) <= 0) {
            $price = (string) ($target['price'] ?? '0');
        }
        if (bccomp($price, '0', 8) <= 0) {
            throw ValidationException::withMessages([
                'compound' => __('Live RACE price is unavailable. Try again in a moment.'),
            ]);
        }

        $race = bcdiv($amount, $price, 8);
        if (bccomp($race, '0', 8) <= 0) {
            throw ValidationException::withMessages([
                'compound' => __('Compound amount is too small to mint.'),
            ]);
        }

        $lock = Cache::lock('virtual-stake-compound:'.$user->id, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'compound' => __('A compound is already in progress. Please wait.'),
            ]);
        }

        try {
            try {
                $minted = $this->mint->mintToWallet($wallet, $race);
            } catch (\Throwable $e) {
                throw ValidationException::withMessages([
                    'compound' => __('RACE mint failed: :msg', ['msg' => $e->getMessage()]),
                ]);
            }

            $mintTx = strtolower((string) ($minted['tx_hash'] ?? ''));
            if (! preg_match('/^0x[a-f0-9]{64}$/', $mintTx)) {
                throw ValidationException::withMessages([
                    'compound' => __('Mint succeeded but no transaction hash was returned.'),
                ]);
            }

            $row = DB::transaction(function () use ($user, $amount, $target, $price, $race, $mintTx) {
                $key = 'virtual_stake_compound:'.$user->id.':'.Str::uuid()->toString();

                return $this->virtualWallet->debit(
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
                        'phase' => 'minted_awaiting_stake',
                        'on_chain_required' => true,
                        'race_amount' => $race,
                        'race_price_usdt' => $price,
                        'mint_tx_hash' => $mintTx,
                    ],
                );
            });

            return [
                'usdt' => $amount,
                'race' => $race,
                'stake_index' => $target['index'],
                'lock_seconds' => $target['lock_seconds'],
                'mint_tx_hash' => $mintTx,
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
     * Last active stake (most recently opened), not the largest principal.
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

            return [
                'index' => (int) $row->stake_index,
                'price' => bccomp($price, '0', 18) > 0 ? $price : '0',
                'lock_seconds' => $lockSeconds,
                'lock_label' => $this->lockLabel($lockSeconds),
                'source_type' => $source,
                'source_label' => $this->sourceLabel($source),
                'principal_usd' => number_format((float) ($row->principal_usdt ?? 0), 2, '.', ''),
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

        $last = $reader->readStakeAt($wallet, $count - 1);
        if ($last === null || ! empty($last['withdrawn'])) {
            return null;
        }

        $lockSeconds = (int) ($last['lock_seconds'] ?? 0);
        $source = BlockchainEngineStake::SOURCE_PARTICIPATION;

        return [
            'index' => $count - 1,
            'price' => (string) ($last['reward_price_usdt'] ?? '0'),
            'lock_seconds' => $lockSeconds,
            'lock_label' => $this->lockLabel($lockSeconds),
            'source_type' => $source,
            'source_label' => $this->sourceLabel($source),
            'principal_usd' => number_format((float) ($last['principal_usdt'] ?? 0), 2, '.', ''),
            'stake_contract' => $this->contractForSource($source),
        ];
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
}
