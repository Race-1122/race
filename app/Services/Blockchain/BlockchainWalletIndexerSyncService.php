<?php

namespace App\Services\Blockchain;

use App\Models\BlockchainEngineStake;
use App\Models\BlockchainEvent;
use App\Models\IcoPurchase;
use App\Models\User;

/**
 * Links indexed on-chain rows to Laravel users by wallet_address (read-model only).
 */
class BlockchainWalletIndexerSyncService
{
    public function syncForUser(User $user): void
    {
        $wallet = $this->normalizeWallet($user->wallet_address ?? '');
        if ($wallet === '') {
            return;
        }

        // Addresses are stored lowercase — equality uses the wallet index (LOWER() cannot).
        IcoPurchase::query()
            ->where('wallet_address', $wallet)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        BlockchainEvent::query()
            ->where('wallet_address', $wallet)
            ->whereNull('user_id')
            ->update(['user_id' => $user->id]);

        static $hasEngineStakes = null;
        $hasEngineStakes ??= \Illuminate\Support\Facades\Schema::hasTable('blockchain_engine_stakes');
        if ($hasEngineStakes) {
            BlockchainEngineStake::query()
                ->where('wallet_address', $wallet)
                ->whereNull('user_id')
                ->update(['user_id' => $user->id]);

            // Member already staked from this wallet (before linking it) → ID must be active now.
            if (($user->id_activated_at === null || $user->participation_activated_at === null)
                && BlockchainEngineStake::query()
                    ->where('user_id', $user->id)
                    ->where(function ($q) {
                        $q->where('withdrawn', false)->orWhereNull('withdrawn');
                    })
                    ->where(function ($q) {
                        $q->whereNull('status')
                            ->orWhere('status', '!=', BlockchainEngineStake::STATUS_WITHDRAWN);
                    })
                    ->exists()) {
                $user->activateIdAndLockWithdrawalsAfterStake();
            }
        }
    }

    public function resolveUserIdForWallet(string $walletAddress): ?int
    {
        $wallet = $this->normalizeWallet($walletAddress);
        if ($wallet === '') {
            return null;
        }

        return User::idByWallet($wallet);
    }

    private function normalizeWallet(?string $wallet): string
    {
        $wallet = strtolower(trim((string) $wallet));

        return preg_match('/^0x[a-f0-9]{40}$/', $wallet) ? $wallet : '';
    }
}
