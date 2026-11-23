<?php

namespace App\Services\Blockchain;

use App\Models\BlockchainEngineStake;
use App\Models\BlockchainEvent;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Parses CommunityEngine stake events into blockchain_engine_stakes read-model rows.
 */
class BlockchainEngineStakeIndexer
{
    public function __construct(
        private readonly BlockchainWalletIndexerSyncService $walletSync,
    ) {}

    /**
     * @param  list<string>  $topics
     */
    public function storeIcoStakeCreated(
        array $topics,
        string $data,
        string $txHash,
        int $blockNumber,
        string $contract,
        mixed $userId,
    ): void {
        if (! \Illuminate\Support\Facades\Schema::hasTable('blockchain_engine_stakes')) {
            return;
        }

        $wallet = isset($topics[1]) ? '0x'.substr((string) $topics[1], 26) : '';
        $stakeIndex = isset($topics[2]) ? hexdec((string) $topics[2]) : null;
        $icoPurchaseId = isset($topics[3]) ? hexdec((string) $topics[3]) : null;

        if ($wallet === '' || $stakeIndex === null || $txHash === '') {
            return;
        }

        $parsed = $this->parseStakeDataFields($data);
        if ($parsed === null) {
            return;
        }

        $walletLower = strtolower($wallet);
        $resolvedUserId = $userId ?? $this->walletSync->resolveUserIdForWallet($walletLower);

        BlockchainEngineStake::query()->updateOrCreate(
            [
                'wallet_address' => $walletLower,
                'stake_index' => (int) $stakeIndex,
                'contract_address' => strtolower($contract),
            ],
            [
                'user_id' => $resolvedUserId,
                'ico_purchase_id' => $icoPurchaseId,
                'principal_usdt' => $parsed['principal_usdt'],
                'staked_race' => $parsed['staked_race'],
                'stake_price_usdt' => $this->stakePriceUsdt($parsed['principal_usdt'], $parsed['staked_race']),
                'lock_seconds' => $parsed['lock_seconds'],
                'daily_rate_bps' => $parsed['daily_rate_bps'],
                'unlock_at' => $parsed['unlock_at'],
                'started_at' => $parsed['started_at'],
                'source_type' => BlockchainEngineStake::SOURCE_ICO,
                'withdrawn' => false,
                'status' => BlockchainEngineStake::STATUS_ACTIVE,
                'tx_hash' => strtolower($txHash),
                'block_number' => $blockNumber,
            ],
        );

        // Any ICO createStake / openIcoStake: activate Member ID + 24h withdrawal lock.
        $this->activateUserAfterStake($resolvedUserId);
        $this->creditInvestmentIfPossible($walletLower, (int) $stakeIndex, strtolower($contract));
    }

    /**
     * RaceWalletRaceStake.StakeOpened → blockchain_engine_stakes (source wallet_race).
     *
     * @param  list<string>  $topics
     */
    public function storeWalletRaceStakeOpened(
        array $topics,
        string $data,
        string $txHash,
        int $blockNumber,
        string $contract,
        mixed $userId,
    ): void {
        $result = $this->upsertWalletRaceStakeRow($topics, $data, $txHash, $blockNumber, $contract, $userId);

        // Pancake / wallet RACE stake must activate Member ID same as ICO createStake (new stake only).
        if ($result['written']) {
            $this->activateUserAfterStake($result['user_id']);
        }
    }

    /**
     * Writes / updates the engine row for one StakeOpened log. Idempotent. No activation, no lock.
     *
     * @return array{written: bool, user_id: ?int}
     */
    public function upsertWalletRaceStakeRow(
        array $topics,
        string $data,
        string $txHash,
        int $blockNumber,
        string $contract,
        mixed $userId,
    ): array {
        $none = ['written' => false, 'user_id' => null];

        if (! \Illuminate\Support\Facades\Schema::hasTable('blockchain_engine_stakes')) {
            return $none;
        }

        $wallet = isset($topics[1]) ? '0x'.substr((string) $topics[1], 26) : '';
        $stakeIndex = isset($topics[2]) ? hexdec((string) $topics[2]) : null;
        if ($wallet === '' || $stakeIndex === null || $txHash === '') {
            return $none;
        }

        $hex = Str::lower(Str::startsWith($data, '0x') ? substr($data, 2) : $data);
        // principalUsdt, raceStaked, lockPeriod, dailyRateBps
        if (strlen($hex) < 256) {
            return $none;
        }

        $usdtWei = $this->hexToDecimalString(substr($hex, 0, 64));
        $raceWei = $this->hexToDecimalString(substr($hex, 64, 64));
        $lockSeconds = (int) hexdec(substr($hex, 128, 64));
        $dailyRateBps = (int) hexdec(substr($hex, 192, 64));
        $walletLower = strtolower($wallet);
        // Canonical key = users.id (resolved from linked wallet); member_number is display-only.
        $resolvedUserId = $userId ?? $this->walletSync->resolveUserIdForWallet($walletLower);

        $principalUsdt = $this->weiToToken($usdtWei);
        $stakedRace = $this->weiToToken($raceWei);

        BlockchainEngineStake::query()->updateOrCreate(
            [
                'wallet_address' => $walletLower,
                'stake_index' => (int) $stakeIndex,
                'contract_address' => strtolower($contract),
            ],
            [
                'user_id' => $resolvedUserId,
                'ico_purchase_id' => null,
                'principal_usdt' => $principalUsdt,
                'staked_race' => $stakedRace,
                'stake_price_usdt' => $this->stakePriceUsdt($principalUsdt, $stakedRace),
                'lock_seconds' => $lockSeconds,
                'daily_rate_bps' => $dailyRateBps,
                'unlock_at' => 0,
                'started_at' => 0,
                'source_type' => BlockchainEngineStake::SOURCE_WALLET_RACE,
                'withdrawn' => false,
                'status' => BlockchainEngineStake::STATUS_ACTIVE,
                'tx_hash' => strtolower($txHash),
                'block_number' => $blockNumber,
            ],
        );

        return [
            'written' => true,
            'user_id' => $resolvedUserId !== null ? (int) $resolvedUserId : null,
        ];
    }

    /**
     * Self-heal: StakeOpened events already saved in blockchain_events whose engine row is missing
     * (engine write failed once after the event was saved). Writes the row. Activation is handled by
     * activateMembersWithOpenStakes() and never adds a withdrawal lock for old stakes.
     */
    private function repairMissingWalletStakeRows(int $limit = 500): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('blockchain_events')) {
            return 0;
        }

        $repaired = 0;
        BlockchainEvent::query()
            ->where('event_name', 'StakeOpened')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (BlockchainEvent $event) use (&$repaired): void {
                $payload = is_array($event->payload) ? $event->payload : [];
                $topics = $payload['topics'] ?? [];
                if (! is_array($topics) || count($topics) < 3) {
                    return;
                }

                $wallet = strtolower((string) $event->wallet_address);
                $contract = strtolower((string) $event->contract_address);
                $stakeIndex = (int) hexdec((string) $topics[2]);

                $exists = BlockchainEngineStake::query()
                    ->where('wallet_address', $wallet)
                    ->where('stake_index', $stakeIndex)
                    ->where('contract_address', $contract)
                    ->exists();
                if ($exists) {
                    return;
                }

                try {
                    $result = $this->upsertWalletRaceStakeRow(
                        $topics,
                        (string) ($payload['data'] ?? ''),
                        (string) $event->tx_hash,
                        (int) $event->block_number,
                        $contract,
                        $event->user_id,
                    );
                    if ($result['written']) {
                        $repaired++;
                    }
                } catch (\Throwable $e) {
                    // One bad event must never block the others (or ID activation).
                    \Illuminate\Support\Facades\Log::error('StakeOpened repair failed', [
                        'event_id' => $event->id,
                        'tx' => $event->tx_hash,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        return $repaired;
    }

    /**
     * @param  list<string>  $topics
     */
    public function markStakeWithdrawn(array $topics, string $data, string $contract): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('blockchain_engine_stakes')) {
            return;
        }

        $wallet = isset($topics[1]) ? '0x'.substr((string) $topics[1], 26) : '';
        if ($wallet === '') {
            return;
        }

        $hex = Str::lower(Str::startsWith($data, '0x') ? substr($data, 2) : $data);
        if (strlen($hex) < 64) {
            return;
        }

        $stakeIndex = (int) hexdec(substr($hex, 0, 64));
        $walletLower = strtolower($wallet);

        $updated = BlockchainEngineStake::query()
            ->where('wallet_address', $walletLower)
            ->where('stake_index', $stakeIndex)
            ->where('contract_address', strtolower($contract))
            ->get();

        BlockchainEngineStake::query()
            ->where('wallet_address', $walletLower)
            ->where('stake_index', $stakeIndex)
            ->where('contract_address', strtolower($contract))
            ->update([
                'withdrawn' => true,
                'status' => BlockchainEngineStake::STATUS_WITHDRAWN,
            ]);

        foreach ($updated as $row) {
            $user = $row->user_id ? User::query()->find($row->user_id) : null;
            if (! $user) {
                continue;
            }
            try {
                app(\App\Services\Income\VirtualIncomeStakeCompoundService::class)
                    ->mintCompoundedOnWithdraw($user, $stakeIndex);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('virtual compound mint after stake withdraw failed', [
                    'user_id' => $user->id,
                    'stake_index' => $stakeIndex,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * RaceICO createStake emits ICOHoldStakeCreated / RaceMintedToStaking (not only Engine ICOStakeCreated).
     *
     * @param  list<string>  $topics
     */
    public function storeFromIcoHoldOrMint(
        string $eventName,
        array $topics,
        string $data,
        string $txHash,
        int $blockNumber,
        mixed $userId,
    ): void {
        if (! \Illuminate\Support\Facades\Schema::hasTable('blockchain_engine_stakes')) {
            return;
        }

        $wallet = '';
        $icoPurchaseId = null;
        $stakeIndex = null;
        $principal = '0';
        $race = '0';
        $lockSeconds = 0;

        $hex = Str::lower(Str::startsWith($data, '0x') ? substr($data, 2) : $data);

        if ($eventName === 'ICOHoldStakeCreated') {
            // buyer[1], purchaseId[2]; data: principalUsdt, raceAmount, price, stakeIndex
            $wallet = isset($topics[1]) ? '0x'.substr((string) $topics[1], 26) : '';
            $icoPurchaseId = isset($topics[2]) ? hexdec((string) $topics[2]) : null;
            if (strlen($hex) >= 256) {
                $principal = $this->weiToToken($this->hexToDecimalString(substr($hex, 0, 64)));
                $race = $this->weiToToken($this->hexToDecimalString(substr($hex, 64, 64)));
                $stakeIndex = (int) hexdec(substr($hex, 192, 64));
            }
        } elseif ($eventName === 'RaceMintedToStaking') {
            // stakingEngine[1], buyer[2], purchaseId[3]; data: raceAmount, lockPeriod, stakeIndex
            $wallet = isset($topics[2]) ? '0x'.substr((string) $topics[2], 26) : '';
            $icoPurchaseId = isset($topics[3]) ? hexdec((string) $topics[3]) : null;
            if (strlen($hex) >= 192) {
                $race = $this->weiToToken($this->hexToDecimalString(substr($hex, 0, 64)));
                $lockSeconds = (int) hexdec(substr($hex, 64, 64));
                $stakeIndex = (int) hexdec(substr($hex, 128, 64));
            }
        }

        if ($wallet === '' || $stakeIndex === null || $txHash === '') {
            return;
        }

        $engine = strtolower(trim((string) config('blockchain.contracts.community_engine', '')));
        if ($engine === '') {
            return;
        }

        $walletLower = strtolower($wallet);
        $resolvedUserId = $userId ?? $this->walletSync->resolveUserIdForWallet($walletLower);
        $unlockAt = $lockSeconds > 0 ? time() + $lockSeconds : 0;

        BlockchainEngineStake::query()->updateOrCreate(
            [
                'wallet_address' => $walletLower,
                'stake_index' => (int) $stakeIndex,
                'contract_address' => $engine,
            ],
            [
                'user_id' => $resolvedUserId,
                'ico_purchase_id' => $icoPurchaseId,
                'principal_usdt' => $principal,
                'staked_race' => $race,
                'stake_price_usdt' => $this->stakePriceUsdt((string) $principal, (string) $race),
                'lock_seconds' => $lockSeconds,
                'daily_rate_bps' => $this->dailyRateBps($lockSeconds),
                'unlock_at' => $unlockAt,
                'started_at' => $lockSeconds > 0 ? time() : 0,
                'source_type' => BlockchainEngineStake::SOURCE_ICO,
                'withdrawn' => false,
                'status' => BlockchainEngineStake::STATUS_ACTIVE,
                'tx_hash' => strtolower($txHash),
                'block_number' => $blockNumber,
            ],
        );

        $this->activateUserAfterStake($resolvedUserId);
        $this->creditInvestmentIfPossible($walletLower, (int) $stakeIndex, $engine);
    }

    public function backfillFromBlockchainEvents(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('blockchain_engine_stakes')) {
            return 0;
        }

        $count = 0;
        BlockchainEvent::query()
            ->whereIn('event_name', ['ICOStakeCreated', 'ICOHoldStakeCreated', 'RaceMintedToStaking', 'StakeOpened'])
            ->orderBy('id')
            ->chunkById(100, function ($events) use (&$count) {
                foreach ($events as $event) {
                    $payload = is_array($event->payload) ? $event->payload : [];
                    $topics = $payload['topics'] ?? [];
                    if (! is_array($topics) || $topics === []) {
                        continue;
                    }

                    if ($event->event_name === 'ICOStakeCreated') {
                        $this->storeIcoStakeCreated(
                            $topics,
                            (string) ($payload['data'] ?? ''),
                            (string) $event->tx_hash,
                            (int) $event->block_number,
                            (string) $event->contract_address,
                            $event->user_id,
                        );
                    } elseif ($event->event_name === 'StakeOpened') {
                        $this->storeWalletRaceStakeOpened(
                            $topics,
                            (string) ($payload['data'] ?? ''),
                            (string) $event->tx_hash,
                            (int) $event->block_number,
                            (string) $event->contract_address,
                            $event->user_id,
                        );
                    } else {
                        $this->storeFromIcoHoldOrMint(
                            (string) $event->event_name,
                            $topics,
                            (string) ($payload['data'] ?? ''),
                            (string) $event->tx_hash,
                            (int) $event->block_number,
                            $event->user_id,
                        );
                    }
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Activate Member ID + participation after any indexed stake (ICO or wallet RACE).
     */
    private function activateUserAfterStake(mixed $userId): void
    {
        if ($userId === null || $userId === '' || (int) $userId <= 0) {
            return;
        }

        $user = User::query()->find((int) $userId);
        if ($user) {
            $user->activateIdAndLockWithdrawalsAfterStake();
        }
    }

    /**
     * Backfill: activate every member who already has an open Engine / wallet stake.
     *
     * @return array{scanned: int, activated: int}
     */
    public function activateMembersWithOpenStakes(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('blockchain_engine_stakes')) {
            return ['scanned' => 0, 'activated' => 0];
        }

        // First: recover engine rows for StakeOpened events that were saved without them.
        $this->repairMissingWalletStakeRows();

        $openStakes = function () {
            return BlockchainEngineStake::query()
                ->where(function ($q) {
                    $q->where('withdrawn', false)
                        ->orWhereNull('withdrawn');
                })
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhere('status', '!=', BlockchainEngineStake::STATUS_WITHDRAWN);
                });
        };

        // Stake indexed before the wallet was linked to a member (user_id = null): link it now.
        $openStakes()
            ->whereNull('user_id')
            ->limit(500)
            ->get()
            ->each(function (BlockchainEngineStake $stake): void {
                $uid = $this->walletSync->resolveUserIdForWallet((string) $stake->wallet_address);
                if ($uid !== null) {
                    $stake->forceFill(['user_id' => $uid])->save();
                }
            });

        $userIds = $openStakes()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        // A saved StakeOpened event with a linked member is proof of a stake, even if the
        // engine row could not be written (activation must never depend on that row).
        if (\Illuminate\Support\Facades\Schema::hasTable('blockchain_events')) {
            $eventUserIds = BlockchainEvent::query()
                ->where('event_name', 'StakeOpened')
                ->whereNotNull('user_id')
                ->distinct()
                ->pluck('user_id');
            $userIds = $userIds->merge($eventUserIds)->unique()->values();
        }

        // Only members still waiting for activation — never re-touch already active members
        // (activation also sets the 24h withdrawal lock; repeating it would re-lock everyone).
        $pending = User::query()
            ->whereIn('id', $userIds)
            ->where(function ($q) {
                $q->whereNull('id_activated_at')
                    ->orWhereNull('participation_activated_at');
            })
            ->get();

        $activated = 0;
        foreach ($pending as $user) {
            // ID only, no 24h withdrawal lock: this path handles stakes that already exist on-chain.
            $user->activateIdWithoutLock();
            $activated++;
        }

        return ['scanned' => $userIds->count(), 'activated' => $activated];
    }

    private function creditInvestmentIfPossible(string $wallet, int $stakeIndex, string $contract): void
    {
        try {
            app(IcoStakeInvestmentCreditor::class)->creditLatestForWalletStake($wallet, $stakeIndex, $contract);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Auto investment credit after ICO stake index failed', [
                'wallet' => $wallet,
                'stake_index' => $stakeIndex,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dailyRateBps(int $lockSeconds): int
    {
        return match ($lockSeconds) {
            0 => 35,
            180 * 86400 => 50,
            365 * 86400 => 70,
            730 * 86400 => 90,
            1095 * 86400 => 100,
            default => 0,
        };
    }

    /**
     * @return array{principal_usdt: string, staked_race: string, lock_seconds: int, daily_rate_bps: int, unlock_at: int, started_at: int}|null
     */
    private function parseStakeDataFields(string $data): ?array
    {
        $hex = Str::lower(Str::startsWith($data, '0x') ? substr($data, 2) : $data);
        if (strlen($hex) < 320) {
            return null;
        }

        $usdtWei = $this->hexToDecimalString(substr($hex, 0, 64));
        $raceWei = $this->hexToDecimalString(substr($hex, 64, 64));
        $lockSeconds = (int) hexdec(substr($hex, 128, 64));
        $dailyRateBps = (int) hexdec(substr($hex, 192, 64));
        $unlockAt = (int) hexdec(substr($hex, 256, 64));
        $startedAt = $unlockAt > 0 && $lockSeconds > 0 ? max(0, $unlockAt - $lockSeconds) : 0;

        return [
            'principal_usdt' => $this->weiToToken($usdtWei),
            'staked_race' => $this->weiToToken($raceWei),
            'lock_seconds' => $lockSeconds,
            'daily_rate_bps' => $dailyRateBps,
            'unlock_at' => $unlockAt,
            'started_at' => $startedAt,
        ];
    }

    private function hexToDecimalString(string $hex64): string
    {
        $hex = ltrim($hex64, '0');
        if ($hex === '') {
            return '0';
        }

        return \App\Support\Hex::wordToDecimal($hex);
    }

    private function weiToToken(string $wei): string
    {
        if (! function_exists('bcdiv')) {
            return number_format(((float) $wei) / 1e18, 18, '.', '');
        }

        return bcdiv($wei, '1000000000000000000', 18);
    }

    /**
     * Stake-time RACE price in USDT (principal / race), locked for audit. Active package uses principal_usdt.
     */
    private function stakePriceUsdt(string $principalUsdt, string $stakedRace): ?string
    {
        if (! function_exists('bccomp') || ! function_exists('bcdiv')) {
            $race = (float) $stakedRace;
            if ($race <= 0) {
                return null;
            }

            return number_format(((float) $principalUsdt) / $race, 18, '.', '');
        }

        if (bccomp($stakedRace, '0', 18) <= 0) {
            return null;
        }

        return bcdiv($principalUsdt, $stakedRace, 18);
    }
}
