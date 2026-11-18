<?php

namespace Tests\Feature;

use App\Models\IncomeWalletTransaction;
use App\Models\StakeMintAdvance;
use App\Models\StakeMintClaim;
use App\Models\User;
use App\Services\Blockchain\RaceCoinDirectMintService;
use App\Services\Income\IncomeClaimService;
use App\Services\Income\StakeMintClaimService;
use App\Services\Income\VirtualIncomeWalletService;
use App\Services\Income\WalletBalanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/** Engine reads are faked; the real mint script is mocked. */
class FakeChainStakeMintClaimService extends StakeMintClaimService
{
    /** @var array<int, array<string, mixed>> */
    public array $stakes = [];

    public ?int $nextAllowed = null;

    protected function readStake(string $wallet, int $index): ?array
    {
        return $this->stakes[$index] ?? null;
    }

    protected function readStakeCount(string $wallet): ?int
    {
        return count($this->stakes);
    }

    protected function readNextAllowedClaimAt(string $wallet): ?int
    {
        return $this->nextAllowed;
    }

    public function enabled(): bool
    {
        return true;
    }
}

class StakeMintClaimTest extends TestCase
{
    use RefreshDatabase;

    private const WALLET = '0x1234567890123456789012345678901234567890';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function inWindow(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 08:00:00', 'Asia/Kolkata'));
    }

    private function user(): User
    {
        return User::factory()->create(['wallet_address' => self::WALLET]);
    }

    /** @param  int  $daysOwed  whole days since the Engine's lastRewardAt */
    private function stake(int $daysOwed): array
    {
        return [
            'principal_usdt' => '100.000000000000000000',
            'staked_race' => '200.000000000000000000',
            'lock_seconds' => 180 * 86400,
            'started_at' => now()->getTimestamp() - 100 * 86400,
            'unlock_at' => now()->getTimestamp() + 80 * 86400,
            'last_reward_at' => now()->getTimestamp() - $daysOwed * 86400,
            'daily_rate_bps' => 50,
            'withdrawn' => false,
            'reward_price_usdt' => '0.500000000000000000',
        ];
    }

    private function service(?RaceCoinDirectMintService $mint = null): FakeChainStakeMintClaimService
    {
        $mint ??= Mockery::mock(RaceCoinDirectMintService::class);

        $service = new FakeChainStakeMintClaimService(
            $mint,
            app(IncomeClaimService::class),
            app(WalletBalanceService::class),
            app(VirtualIncomeWalletService::class),
        );
        $service->nextAllowed = now()->getTimestamp() + 3600; // on-chain claim already used today

        return $service;
    }

    private function mintReturning(string $expectedRace): RaceCoinDirectMintService
    {
        $mint = Mockery::mock(RaceCoinDirectMintService::class);
        $mint->shouldReceive('mintToWallet')
            ->once()
            ->with(self::WALLET, $expectedRace)
            ->andReturn(['tx_hash' => '0x'.str_repeat('a', 64), 'to' => self::WALLET, 'amount_wei' => '0', 'minter' => '0x0']);

        return $mint;
    }

    public function test_claim_mints_engine_roi_and_records_advance(): void
    {
        $this->inWindow();
        $user = $this->user();
        // 100 USDT × 0.50% × 3 days = 1.5 USDT, at $0.50 per RACE = 3 RACE (same formula as the Engine).
        $service = $this->service($this->mintReturning('3.00000000'));
        $service->stakes = [1 => $this->stake(3)];

        $claim = $service->claim($user, 1);

        $this->assertSame(StakeMintClaim::STATUS_COMPLETED, $claim->status);
        $this->assertSame(3, (int) $claim->days);
        $advance = StakeMintAdvance::query()->where('user_id', $user->id)->where('stake_index', 1)->firstOrFail();
        $this->assertSame(3, (int) $advance->days_outstanding);
        $this->assertSame(0, bccomp((string) $advance->usdt_outstanding, '1.5', 8));
    }

    public function test_second_claim_same_day_has_nothing_to_claim(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service($this->mintReturning('3.00000000'));
        $service->stakes = [1 => $this->stake(3)];
        $service->claim($user, 1);

        $this->expectException(ValidationException::class);
        $service->claim($user, 1); // no double claim: Engine owed 3, advance 3 → 0 left
    }

    public function test_next_day_only_the_new_day_is_claimable(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service($this->mintReturning('3.00000000'));
        $service->stakes = [1 => $this->stake(3)];
        $service->claim($user, 1);

        Carbon::setTestNow(Carbon::parse('2026-11-19 08:00:00', 'Asia/Kolkata'));
        $stake = $service->stakes[1];
        $adv = StakeMintAdvance::query()->where('user_id', $user->id)->firstOrFail();
        // Engine now owes 4 days (nothing settled on-chain), 3 already advanced → exactly 1 day.
        $stake['last_reward_at'] = $service->stakes[1]['last_reward_at'];
        $this->assertSame(1, $service->eligibleDays($stake, $adv, now()->getTimestamp()));
    }

    public function test_requires_on_chain_claim_first(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service();
        $service->nextAllowed = 0; // wallet can still claim on-chain
        $service->stakes = [1 => $this->stake(3)];

        $this->expectException(ValidationException::class);
        $service->claim($user, 1);
    }

    public function test_blocked_outside_claim_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-18 13:00:00', 'Asia/Kolkata'));
        $user = $this->user();
        $service = $this->service();
        $service->stakes = [1 => $this->stake(3)];

        $this->expectException(ValidationException::class);
        $service->claim($user, 1);
    }

    public function test_failed_mint_pays_nothing_and_records_nothing(): void
    {
        $this->inWindow();
        $user = $this->user();
        $mint = Mockery::mock(RaceCoinDirectMintService::class);
        $mint->shouldReceive('mintToWallet')->once()->andThrow(new \RuntimeException('rpc down'));
        $service = $this->service($mint);
        $service->stakes = [1 => $this->stake(3)];

        try {
            $service->claim($user, 1);
            $this->fail('Expected ValidationException');
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, StakeMintAdvance::query()->count());
        $this->assertSame(StakeMintClaim::STATUS_FAILED, StakeMintClaim::query()->firstOrFail()->status);
    }

    public function test_engine_paying_same_days_is_recouped_from_virtual_income(): void
    {
        $this->inWindow();
        $user = $this->user();
        app(VirtualIncomeWalletService::class)->credit(
            $user,
            IncomeWalletTransaction::TYPE_ADJUSTMENT,
            '10.00',
            'test:seed',
            'seed:'.$user->id,
        );

        $service = $this->service($this->mintReturning('3.00000000'));
        $service->stakes = [1 => $this->stake(3)];
        $service->claim($user, 1);

        // Later the user claims that stake on-chain: Engine settles the same 3 days (lastRewardAt moves +3d).
        $service->stakes[1]['last_reward_at'] += 3 * 86400;
        $service->reconcileUser($user);

        $advance = StakeMintAdvance::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, (int) $advance->days_outstanding);
        $this->assertSame(0, bccomp((string) $advance->recoup_pending_usdt, '0', 8));
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user), '8.5', 4));
    }

    public function test_recoup_shortfall_stays_pending_until_balance_exists(): void
    {
        $this->inWindow();
        $user = $this->user();
        app(VirtualIncomeWalletService::class)->credit(
            $user,
            IncomeWalletTransaction::TYPE_ADJUSTMENT,
            '0.50',
            'test:seed',
            'seed:'.$user->id,
        );

        $service = $this->service($this->mintReturning('3.00000000'));
        $service->stakes = [1 => $this->stake(3)];
        $service->claim($user, 1);

        $service->stakes[1]['last_reward_at'] += 3 * 86400;
        $service->reconcileUser($user);

        $advance = StakeMintAdvance::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $advance->recoup_pending_usdt, '1', 8));
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user), '0', 4));

        app(VirtualIncomeWalletService::class)->credit(
            $user,
            IncomeWalletTransaction::TYPE_ADJUSTMENT,
            '5.00',
            'test:seed2',
            'seed2:'.$user->id,
        );
        $service->reconcileUser($user);

        $advance->refresh();
        $this->assertSame(0, bccomp((string) $advance->recoup_pending_usdt, '0', 8));
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user), '4', 4));
    }

    public function test_matured_or_withdrawn_stake_is_not_claimable(): void
    {
        $this->inWindow();
        $service = $this->service();

        $matured = $this->stake(3);
        $matured['daily_rate_bps'] = 0;
        $this->assertSame(0, $service->engineOwedDays($matured, now()->getTimestamp()));

        $withdrawn = $this->stake(3);
        $withdrawn['withdrawn'] = true;
        $this->assertSame(0, $service->engineOwedDays($withdrawn, now()->getTimestamp()));
    }

    public function test_admin_switch_controls_claim_by_mint(): void
    {
        config([
            'race_income_mint.minter_private_key' => '0xabc',
            'race_income_mint.race_token' => self::WALLET,
        ]);
        $service = new StakeMintClaimService(
            app(RaceCoinDirectMintService::class),
            app(IncomeClaimService::class),
            app(WalletBalanceService::class),
            app(VirtualIncomeWalletService::class),
        );

        $this->assertFalse($service->enabled()); // default OFF

        \App\Models\SiteSetting::set(\App\Models\SiteSetting::KEY_STAKE_MINT_CLAIM_ENABLED, '1');
        $this->assertTrue($service->enabled());

        \App\Models\SiteSetting::set(\App\Models\SiteSetting::KEY_STAKE_MINT_CLAIM_ENABLED, '0');
        $this->assertFalse($service->enabled());

        // Switch ON but no minter key on the server → still disabled.
        \App\Models\SiteSetting::set(\App\Models\SiteSetting::KEY_STAKE_MINT_CLAIM_ENABLED, '1');
        config(['race_income_mint.minter_private_key' => '']);
        $this->assertFalse($service->enabled());
    }

    public function test_dashboard_shows_minted_claims_and_today_total(): void
    {
        $this->inWindow();
        $user = $this->user();

        StakeMintClaim::query()->create([
            'user_id' => $user->id,
            'stake_index' => 1,
            'wallet_address' => self::WALLET,
            'days' => 3,
            'usdt_amount' => '1.5',
            'race_amount' => '3',
            'race_price_usdt' => '0.5',
            'status' => StakeMintClaim::STATUS_COMPLETED,
            'tx_hash' => '0x'.str_repeat('b', 64),
        ]);
        StakeMintClaim::query()->create([
            'user_id' => $user->id,
            'stake_index' => 2,
            'wallet_address' => self::WALLET,
            'days' => 1,
            'usdt_amount' => '9',
            'race_amount' => '9',
            'race_price_usdt' => '1',
            'status' => StakeMintClaim::STATUS_FAILED, // failed claims never show
        ]);

        $coin = Mockery::mock(\App\Services\Income\RaceCoinService::class);
        $coin->shouldReceive('priceUsd')->andReturn(0.5);

        $controller = app(\App\Http\Controllers\DashboardController::class);
        $method = new \ReflectionMethod($controller, 'dailyClaimsForUser');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $user, $coin);

        $this->assertSame(1, $result['count']);
        $this->assertSame('mint', $result['items'][0]['source']);
        $this->assertSame(1, $result['today_count']);
        $this->assertSame(0, bccomp($result['today_race'], '3', 6));
        $this->assertSame(0, bccomp($result['today_value_claim_usd'], '1.5', 6));
        $this->assertSame(0, bccomp($result['today_value_now_usd'], '1.5', 4)); // 3 RACE × $0.5
    }

    public function test_fixed_plan_accrual_stops_at_unlock(): void
    {
        $this->inWindow();
        $service = $this->service();

        $stake = $this->stake(200);
        $stake['unlock_at'] = $stake['last_reward_at'] + 10 * 86400;

        $this->assertSame(10, $service->engineOwedDays($stake, now()->getTimestamp()));
    }
}
