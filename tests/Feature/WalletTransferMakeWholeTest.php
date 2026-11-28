<?php

namespace Tests\Feature;

use App\Models\RaceCoinSwap;
use App\Models\RaceTransferMakeWhole;
use App\Models\User;
use App\Services\Blockchain\RaceCoinDirectMintService;
use App\Services\Blockchain\WalletTransferMakeWholeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class WalletTransferMakeWholeTest extends TestCase
{
    use RefreshDatabase;

    private const RACE = '0x4444444444444444444444444444444444444444';

    private const PAIR = '0x3333333333333333333333333333333333333333';

    private const ALICE = '0x1111111111111111111111111111111111111111';

    private const BOB = '0x2222222222222222222222222222222222222222';

    private const FEE1 = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const TX = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const SENT = '100000000000000000000'; // 100 RACE

    private const FEE = '4000000000000000000'; // 4 RACE

    private const NET = '96000000000000000000'; // 96 RACE

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config([
            'blockchain.contracts.race_token' => self::RACE,
            'blockchain.contracts.pancake_race_usdt_pair' => self::PAIR,
            'blockchain.wallet_transfer_make_whole.enabled' => true,
            'blockchain.wallet_transfer_make_whole.skip_live_fee_wallets' => true,
            'blockchain.wallet_transfer_make_whole.extra_skip' => [
                self::FEE1,
                '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                '0xcccccccccccccccccccccccccccccccccccccccc',
                '0xdddddddddddddddddddddddddddddddddddddddd',
            ],
            'race_income_mint.race_token' => self::RACE,
            'race_income_mint.minter_private_key' => '0xabc',
        ]);
    }

    public function test_wallet_to_wallet_mints_exact_fee_to_recipient(): void
    {
        $mint = Mockery::mock(RaceCoinDirectMintService::class);
        $mint->shouldReceive('configured')->andReturn(true);
        $mint->shouldReceive('mintToWalletWei')
            ->once()
            ->with(self::BOB, self::FEE)
            ->andReturn(['tx_hash' => '0x'.str_repeat('c', 64), 'to' => self::BOB, 'amount_wei' => self::FEE, 'minter' => self::ALICE]);

        $service = $this->service($mint);
        $out = $service->makeWholeFromReceipt($this->w2wReceipt(), self::TX);

        $this->assertSame('completed', $out['status']);
        $this->assertTrue($out['minted']);
        $this->assertSame(self::FEE, $out['fee_wei']);
        $this->assertSame(self::BOB, $out['to']);

        $row = RaceTransferMakeWhole::query()->where('tx_hash', self::TX)->first();
        $this->assertNotNull($row);
        $this->assertSame(self::FEE, $row->fee_wei);
        $this->assertSame(self::NET, $row->received_wei);
        $this->assertSame(RaceTransferMakeWhole::STATUS_COMPLETED, $row->status);
    }

    public function test_second_call_does_not_mint_again(): void
    {
        $mint = Mockery::mock(RaceCoinDirectMintService::class);
        $mint->shouldReceive('configured')->andReturn(true);
        $mint->shouldReceive('mintToWalletWei')->once()->andReturn([
            'tx_hash' => '0x'.str_repeat('c', 64),
            'to' => self::BOB,
            'amount_wei' => self::FEE,
            'minter' => self::ALICE,
        ]);

        $service = $this->service($mint);
        $service->makeWholeFromReceipt($this->w2wReceipt(), self::TX);
        $again = $service->makeWholeFromReceipt($this->w2wReceipt(), self::TX);

        $this->assertSame('already_done', $again['reason'] ?? null);
        $this->assertSame(1, RaceTransferMakeWhole::query()->count());
    }

    public function test_sell_to_pair_is_not_made_whole(): void
    {
        $mint = Mockery::mock(RaceCoinDirectMintService::class);
        $mint->shouldReceive('configured')->andReturn(true);
        $mint->shouldNotReceive('mintToWalletWei');

        $service = $this->service($mint);
        $out = $service->makeWholeFromReceipt($this->transferReceipt(self::ALICE, self::PAIR), self::TX);

        $this->assertSame('skipped', $out['status']);
        $this->assertFalse($out['minted']);
        $this->assertSame('sell_or_no_recipient', $out['reason']);
    }

    public function test_buy_from_pair_is_not_made_whole(): void
    {
        $mint = Mockery::mock(RaceCoinDirectMintService::class);
        $mint->shouldReceive('configured')->andReturn(true);
        $mint->shouldNotReceive('mintToWalletWei');

        $service = $this->service($mint);
        $out = $service->makeWholeFromReceipt($this->transferReceipt(self::PAIR, self::BOB), self::TX);

        $this->assertSame('skipped', $out['status']);
        $this->assertSame('from_protocol', $out['reason']);
    }

    public function test_existing_buy_make_whole_row_is_not_double_minted(): void
    {
        $user = User::factory()->create();
        RaceCoinSwap::query()->create([
            'user_id' => $user->id,
            'swap_type' => RaceCoinSwap::TYPE_PANCAKE_BUY_MAKE_WHOLE,
            'usdt_amount' => 10,
            'coins_amount' => 4,
            'balance_after_coins' => 0,
            'tx_hash' => self::TX,
            'meta' => [],
        ]);

        $mint = Mockery::mock(RaceCoinDirectMintService::class);
        $mint->shouldReceive('configured')->andReturn(true);
        $mint->shouldNotReceive('mintToWalletWei');

        $service = $this->service($mint);
        $out = $service->makeWholeFromReceipt($this->w2wReceipt(), self::TX);

        $this->assertSame('buy_make_whole', $out['reason']);
    }

    public function test_parse_sent_equals_received_plus_fee(): void
    {
        $mint = Mockery::mock(RaceCoinDirectMintService::class);
        $mint->shouldReceive('configured')->andReturn(true);
        $parsed = $this->service($mint)->parseReceipt($this->w2wReceipt());

        $this->assertSame('', $parsed['reason']);
        $this->assertSame(self::FEE, $parsed['fee_wei']);
        $this->assertSame(self::NET, $parsed['received_wei']);
        $this->assertSame(self::SENT, $parsed['sent_wei']);
        $this->assertSame(self::BOB, $parsed['to']);
        $this->assertSame(self::ALICE, $parsed['from']);
    }

    private function service(RaceCoinDirectMintService $mint): WalletTransferMakeWholeService
    {
        return app()->makeWith(WalletTransferMakeWholeService::class, ['mint' => $mint]);
    }

    /** @return array<string, mixed> */
    private function w2wReceipt(): array
    {
        return $this->transferReceipt(self::ALICE, self::BOB);
    }

    /** @return array<string, mixed> */
    private function transferReceipt(string $from, string $to): array
    {
        $fees = [
            self::FEE1,
            '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            '0xcccccccccccccccccccccccccccccccccccccccc',
            '0xdddddddddddddddddddddddddddddddddddddddd',
        ];
        $part = '1000000000000000000';
        $logs = [];
        foreach ($fees as $wallet) {
            $logs[] = $this->transferLog($from, $wallet, $part);
        }
        $logs[] = $this->transferLog($from, $to, self::NET);
        $logs[] = $this->feeCollectedLog($from, self::FEE);

        return [
            'status' => '0x1',
            'transactionHash' => self::TX,
            'logs' => $logs,
        ];
    }

    /** @return array<string, mixed> */
    private function transferLog(string $from, string $to, string $wei): array
    {
        return [
            'address' => self::RACE,
            'topics' => [
                '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                $this->topicAddr($from),
                $this->topicAddr($to),
            ],
            'data' => $this->weiToHexWord($wei),
        ];
    }

    /** @return array<string, mixed> */
    private function feeCollectedLog(string $from, string $wei): array
    {
        return [
            'address' => self::RACE,
            'topics' => [
                '0x06c5efeff5c320943d265dc4e5f1af95ad523555ce0c1957e367dda5514572df',
                $this->topicAddr($from),
            ],
            'data' => $this->weiToHexWord($wei),
        ];
    }

    private function topicAddr(string $addr): string
    {
        return '0x'.str_pad(substr($addr, 2), 64, '0', STR_PAD_LEFT);
    }

    private function weiToHexWord(string $wei): string
    {
        $n = $wei;
        $hex = '';
        while (bccomp($n, '0', 0) > 0) {
            $hex = dechex((int) bcmod($n, '16')).$hex;
            $n = bcdiv($n, '16', 0);
        }

        return '0x'.str_pad($hex === '' ? '0' : $hex, 64, '0', STR_PAD_LEFT);
    }
}
