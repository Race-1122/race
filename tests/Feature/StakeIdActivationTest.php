<?php

namespace Tests\Feature;

use App\Models\BlockchainEngineStake;
use App\Models\BlockchainEvent;
use App\Models\User;
use App\Services\Blockchain\BlockchainEngineStakeIndexer;
use App\Services\Blockchain\BlockchainEventIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StakeIdActivationTest extends TestCase
{
    use RefreshDatabase;

    private const STAKE_CONTRACT = '0xbcb76726d87e0c794ae34bbf692c2d204ef9b29c';

    private const STAKE_OPENED_TOPIC = '0x16095dd9f69a744798b6add21e5f0b8d9a5aa4d4a65b0a1b37682df0d8c2c5bc';

    private const WALLET = '0x1054fba84e3be2729b14617cb53cc66da89739c4';

    private const TX = '0xd28df0ac07b609a98c1a7151e785528f4c4e3745cb087f1f0b6ee3513249552b';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'blockchain.rpc_urls' => ['https://rpc.test'],
            'blockchain.indexer.rpc_urls' => ['https://rpc.test'],
            'blockchain.contracts.wallet_race_stake' => self::STAKE_CONTRACT,
        ]);
    }

    private function fakeStakeReceipt(string $eventWallet): void
    {
        $receipt = [
            'status' => '0x1',
            'logs' => [[
                'address' => self::STAKE_CONTRACT,
                'topics' => [
                    self::STAKE_OPENED_TOPIC,
                    '0x'.str_pad(substr($eventWallet, 2), 64, '0', STR_PAD_LEFT),
                    '0x'.str_pad('0', 64, '0', STR_PAD_LEFT),
                ],
                'data' => '0x',
                'logIndex' => '0x0',
                'blockNumber' => '0x1',
                'transactionHash' => self::TX,
            ]],
        ];

        Http::fake([
            'rpc.test' => Http::response(['jsonrpc' => '2.0', 'id' => 1, 'result' => $receipt]),
        ]);
    }

    public function test_stake_tx_activates_member_id_immediately(): void
    {
        $user = User::factory()->create([
            'wallet_address' => self::WALLET,
            'id_activated_at' => null,
            'participation_activated_at' => null,
        ]);
        $this->fakeStakeReceipt(self::WALLET);

        $ok = app(BlockchainEventIngestService::class)->activateUserFromStakeTx($user, self::TX);

        $this->assertTrue($ok);
        $user->refresh();
        $this->assertNotNull($user->id_activated_at);
        $this->assertNotNull($user->participation_activated_at);
    }

    public function test_stake_tx_of_another_wallet_does_not_activate(): void
    {
        $user = User::factory()->create([
            'wallet_address' => self::WALLET,
            'id_activated_at' => null,
            'participation_activated_at' => null,
        ]);
        $this->fakeStakeReceipt('0x000000000000000000000000000000000000dead');

        $ok = app(BlockchainEventIngestService::class)->activateUserFromStakeTx($user, self::TX);

        $this->assertFalse($ok);
        $user->refresh();
        $this->assertNull($user->id_activated_at);
        $this->assertNull($user->participation_activated_at);
    }

    public function test_safety_net_links_unlinked_stake_and_activates_but_never_relocks_active_members(): void
    {
        $pending = User::factory()->create([
            'wallet_address' => self::WALLET,
            'id_activated_at' => null,
            'participation_activated_at' => null,
        ]);
        $active = User::factory()->create([
            'wallet_address' => '0x2222222222222222222222222222222222222222',
            'id_activated_at' => now()->subDay(),
            'participation_activated_at' => now()->subDay(),
            'withdrawals_locked_until' => null,
        ]);

        // Stake indexed while the wallet was not linked yet (user_id null).
        $this->makeStake(self::WALLET, null, 0);
        $this->makeStake('0x2222222222222222222222222222222222222222', $active->id, 0);

        $result = app(BlockchainEngineStakeIndexer::class)->activateMembersWithOpenStakes();

        $this->assertSame(1, $result['activated']);
        $pending->refresh();
        $active->refresh();
        $this->assertNotNull($pending->id_activated_at);
        $this->assertNotNull($pending->participation_activated_at);
        $this->assertNull($active->withdrawals_locked_until, 'already-active members must not be re-locked');
    }

    /** StakeOpened data: principalUsdt=1e18, raceStaked=2e18, lockPeriod=1095d, dailyRateBps=100. */
    private function stakeOpenedData(): string
    {
        return '0x'
            .str_pad('de0b6b3a7640000', 64, '0', STR_PAD_LEFT)
            .str_pad('1bc16d674ec80000', 64, '0', STR_PAD_LEFT)
            .str_pad('5a39a80', 64, '0', STR_PAD_LEFT)
            .str_pad('64', 64, '0', STR_PAD_LEFT);
    }

    private function storeStakeOpenedEvent(User $user, string $wallet, int $index = 0): void
    {
        BlockchainEvent::query()->create([
            'user_id' => $user->id,
            'wallet_address' => $wallet,
            'contract_address' => self::STAKE_CONTRACT,
            'event_name' => 'StakeOpened',
            'tx_hash' => self::TX,
            'log_index' => $index,
            'block_number' => 1,
            'payload' => [
                'topics' => [
                    self::STAKE_OPENED_TOPIC,
                    '0x'.str_pad(substr($wallet, 2), 64, '0', STR_PAD_LEFT),
                    '0x'.str_pad(dechex($index), 64, '0', STR_PAD_LEFT),
                ],
                'data' => $this->stakeOpenedData(),
            ],
        ]);
    }

    public function test_missing_engine_row_is_repaired_and_id_activated_without_lock(): void
    {
        $user = User::factory()->create([
            'wallet_address' => self::WALLET,
            'id_activated_at' => null,
            'participation_activated_at' => null,
            'withdrawals_locked_until' => null,
        ]);
        // Event saved, but engine write never happened (the reported bug).
        $this->storeStakeOpenedEvent($user, self::WALLET, 0);

        app(BlockchainEngineStakeIndexer::class)->activateMembersWithOpenStakes();

        $this->assertDatabaseHas('blockchain_engine_stakes', [
            'wallet_address' => self::WALLET,
            'stake_index' => 0,
            'user_id' => $user->id,
        ]);
        $user->refresh();
        $this->assertNotNull($user->id_activated_at);
        $this->assertNotNull($user->participation_activated_at);
        $this->assertNull($user->withdrawals_locked_until, 'old stakes must not add a 24h withdrawal lock');
    }

    public function test_repair_never_relocks_an_already_active_member(): void
    {
        $user = User::factory()->create([
            'wallet_address' => self::WALLET,
            'id_activated_at' => now()->subDays(3),
            'participation_activated_at' => now()->subDays(3),
            'withdrawals_locked_until' => null,
        ]);
        $this->storeStakeOpenedEvent($user, self::WALLET, 0);

        $indexer = app(BlockchainEngineStakeIndexer::class);
        $indexer->activateMembersWithOpenStakes();
        $indexer->activateMembersWithOpenStakes();

        $user->refresh();
        $this->assertNull($user->withdrawals_locked_until);
        $this->assertDatabaseCount('blockchain_engine_stakes', 1);
    }

    private function makeStake(string $wallet, ?int $userId, int $index): void
    {
        BlockchainEngineStake::query()->create([
            'user_id' => $userId,
            'wallet_address' => $wallet,
            'stake_index' => $index,
            'contract_address' => self::STAKE_CONTRACT,
            'principal_usdt' => '48.5278',
            'staked_race' => '65.4336',
            'stake_price_usdt' => '0.7416',
            'lock_seconds' => 1095 * 86400,
            'daily_rate_bps' => 100,
            'started_at' => 0,
            'unlock_at' => 0,
            'source_type' => BlockchainEngineStake::SOURCE_WALLET_RACE,
            'withdrawn' => false,
            'status' => BlockchainEngineStake::STATUS_ACTIVE,
            'tx_hash' => self::TX.$index,
            'block_number' => 1,
        ]);
    }
}
