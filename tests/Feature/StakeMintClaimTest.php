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

/**
 * Engine empty, WalletRace live — same split #1179 hits on withdraw reconcile.
 */
class FakeSplitChainStakeMintClaimService extends StakeMintClaimService
{
    /** @var array<string, mixed>|null */
    public ?array $engineStake = null;

    /** @var array<string, mixed>|null */
    public ?array $walletRaceStake = null;

    public ?int $nextAllowed = null;

    protected function readEngineStake(string $wallet, int $index): ?array
    {
        return $this->engineStake;
    }

    protected function readWalletRaceStake(string $wallet, int $index): ?array
    {
        return $this->walletRaceStake;
    }

    protected function readEngineStakeCount(string $wallet): ?int
    {
        return $this->engineStake !== null ? 1 : 0;
    }

    protected function readWalletRaceStakeCount(string $wallet): ?int
    {
        return $this->walletRaceStake !== null ? 1 : 0;
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

    public function test_claim_credits_virtual_income_and_records_advance(): void
    {
        $this->inWindow();
        $user = $this->user();
        // 100 USDT × 0.50% × 3 days = 1.5 USDT, at $0.50 per RACE = 3 RACE (display only — no mint on claim).
        $service = $this->service();
        $service->stakes = [1 => $this->stake(3)];

        $claim = $service->claim($user, 1);

        $this->assertSame(StakeMintClaim::STATUS_COMPLETED, $claim->status);
        $this->assertNull($claim->tx_hash);
        $this->assertSame(3, (int) $claim->days);
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user), '1.5', 4));
        $advance = StakeMintAdvance::query()->where('user_id', $user->id)->where('stake_index', 1)->firstOrFail();
        $this->assertSame(3, (int) $advance->days_outstanding);
        $this->assertSame(0, bccomp((string) $advance->usdt_outstanding, '1.5', 8));
        $this->assertSame(1, IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', IncomeWalletTransaction::TYPE_INCOME_CLAIM)
            ->where('direction', IncomeWalletTransaction::DIRECTION_CREDIT)
            ->count());
    }

    public function test_second_claim_same_day_has_nothing_to_claim(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service();
        $service->stakes = [1 => $this->stake(3)];
        $service->claim($user, 1);

        $this->expectException(ValidationException::class);
        $service->claim($user, 1); // no double claim: Engine owed 3, advance 3 → 0 left
    }

    public function test_next_day_only_the_new_day_is_claimable(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service();
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
        config(['income.claim.window_enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-18 13:00:00', 'Asia/Kolkata'));
        $user = $this->user();
        $service = $this->service();
        $service->stakes = [1 => $this->stake(3)];

        $this->expectException(ValidationException::class);
        $service->claim($user, 1);
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

        $service = $this->service();
        $service->stakes = [1 => $this->stake(3)];
        $service->claim($user, 1);

        // Later the user claims that stake on-chain: Engine settles the same 3 days (lastRewardAt moves +3d).
        $service->stakes[1]['last_reward_at'] += 3 * 86400;
        $service->reconcileUser($user);

        $advance = StakeMintAdvance::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, (int) $advance->days_outstanding);
        $this->assertSame(0, bccomp((string) $advance->recoup_pending_usdt, '0', 8));
        // seed 10 + claim credit 1.5 − recoup 1.5 = 10
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user), '10', 4));
    }

    public function test_recoup_shortfall_stays_pending_until_balance_exists(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service();
        $service->stakes = [1 => $this->stake(3)];
        $service->claim($user, 1); // +1.5 virtual

        // User already withdrew the virtual credit before the Engine paid the same days.
        app(VirtualIncomeWalletService::class)->debit(
            $user,
            IncomeWalletTransaction::TYPE_WITHDRAWAL,
            '1.50',
            'test:drain',
            'drain:'.$user->id,
        );

        $service->stakes[1]['last_reward_at'] += 3 * 86400;
        $service->reconcileUser($user);

        $advance = StakeMintAdvance::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(0, bccomp((string) $advance->recoup_pending_usdt, '1.5', 8));
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
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user), '3.5', 4));
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
        $service = new StakeMintClaimService(
            app(RaceCoinDirectMintService::class),
            app(IncomeClaimService::class),
            app(WalletBalanceService::class),
            app(VirtualIncomeWalletService::class),
        );

        $this->assertFalse($service->enabled()); // default OFF

        \App\Models\SiteSetting::set(\App\Models\SiteSetting::KEY_STAKE_MINT_CLAIM_ENABLED, '1');
        $this->assertTrue($service->enabled()); // virtual credit does not need minter key

        \App\Models\SiteSetting::set(\App\Models\SiteSetting::KEY_STAKE_MINT_CLAIM_ENABLED, '0');
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
        $old = StakeMintClaim::query()->create([
            'user_id' => $user->id,
            'stake_index' => 3,
            'wallet_address' => self::WALLET,
            'days' => 1,
            'usdt_amount' => '2',
            'race_amount' => '4',
            'race_price_usdt' => '0.5',
            'status' => StakeMintClaim::STATUS_COMPLETED,
            'tx_hash' => '0x'.str_repeat('c', 64),
        ]);
        $old->forceFill([
            'created_at' => Carbon::parse('2026-11-17 04:00:00', 'Asia/Kolkata'),
        ])->save();

        $coin = Mockery::mock(\App\Services\Income\RaceCoinService::class);
        $coin->shouldReceive('priceUsd')->andReturn(0.5);

        $controller = app(\App\Http\Controllers\DashboardController::class);
        $method = new \ReflectionMethod($controller, 'dailyClaimsForUser');
        $method->setAccessible(true);
        $result = $method->invoke($controller, $user, $coin);

        $this->assertSame(2, $result['count']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('mint', $result['items'][0]['source']);
        $this->assertSame(1, $result['items'][0]['stake_index']);
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

    public function test_auto_claim_credits_all_due_engine_stakes_to_virtual(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service();
        $service->stakes = [
            0 => $this->stake(1),
            1 => $this->stake(3),
        ];

        $out = $service->autoClaimUser($user);

        $this->assertSame(2, $out['claims']);
        // Cron credits at most 1 day per stake per run (0.50% of $100 = $0.50 each).
        $this->assertSame(0, bccomp($out['usdt'], '1.0', 4));
        $this->assertSame(2, StakeMintAdvance::query()->where('user_id', $user->id)->count());
    }

    public function test_auto_claim_credits_wallet_race_when_engine_count_is_zero(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = new FakeSplitChainStakeMintClaimService(
            Mockery::mock(RaceCoinDirectMintService::class),
            app(IncomeClaimService::class),
            app(WalletBalanceService::class),
            app(VirtualIncomeWalletService::class),
        );
        $service->nextAllowed = now()->getTimestamp() + 3600;
        $stake = $this->stake(0);
        $stake['last_reward_at'] = 0;
        $service->walletRaceStake = $stake;

        $out = $service->autoClaimUser($user);

        $this->assertSame(1, $out['claims']);
        $this->assertSame(0, bccomp($out['usdt'], '0.50', 4));
        $this->assertSame(1, StakeMintClaim::query()->where('user_id', $user->id)->count());
    }

    public function test_auto_claim_credits_onchain_stake_even_when_wallet_cooldown_is_open(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service();
        $service->nextAllowed = 0; // Engine would still accept claimReward from the wallet
        $service->stakes = [0 => $this->stake(1)];

        $out = $service->autoClaimUser($user);

        $this->assertSame(1, $out['claims']);
        $this->assertSame(0, bccomp($out['usdt'], '0.5', 4));
    }

    public function test_compounded_virtual_raises_next_daily_claim(): void
    {
        $this->inWindow();
        $user = $this->user();
        app(VirtualIncomeWalletService::class)->credit(
            $user,
            IncomeWalletTransaction::TYPE_INCOME_CLAIM,
            '50.00',
            'test:seed',
            'seed-compound:'.$user->id,
        );
        \App\Models\BlockchainEngineStake::query()->create([
            'user_id' => $user->id,
            'wallet_address' => self::WALLET,
            'stake_index' => 0,
            'principal_usdt' => '100',
            'staked_race' => '200',
            'stake_price_usdt' => '0.5',
            'lock_seconds' => 180 * 86400,
            'daily_rate_bps' => 50,
            'started_at' => now()->getTimestamp() - 10 * 86400,
            'unlock_at' => now()->getTimestamp() + 170 * 86400,
            'source_type' => 'wallet_race',
            'withdrawn' => false,
            'status' => 'active',
            'tx_hash' => '0x'.str_repeat('c', 64),
            'contract_address' => '0x'.str_repeat('d', 40),
        ]);

        \App\Models\VirtualStakeCompound::query()->create([
            'user_id' => $user->id,
            'stake_index' => 0,
            'usdt_amount' => '50.00',
            'race_amount' => '100',
            'race_price_usdt' => '0.5',
        ]);

        $service = $this->service();
        $service->stakes = [0 => $this->stake(1)];
        $claim = $service->claim($user, 0, ['from_cron' => true]);

        // Engine principal stays $100 → 0.50% = $0.50 (advance). Overlay $50 is a separate 04:00 credit.
        $this->assertSame(0, bccomp((string) $claim->usdt_amount, '0.50', 4));
        $this->assertSame(0, bccomp(
            (string) IncomeWalletTransaction::query()
                ->where('user_id', $user->id)
                ->where('type', IncomeWalletTransaction::TYPE_INCOME_CLAIM)
                ->where('source_reference', '!=', 'test:seed')
                ->sum('amount'),
            '0.75',
            4,
        ));
        $advance = StakeMintAdvance::query()->where('user_id', $user->id)->where('stake_index', 0)->firstOrFail();
        $this->assertSame(0, bccomp((string) $advance->usdt_outstanding, '0.50', 8));
        $this->assertSame(1, (int) $advance->days_outstanding);
    }

    public function test_cron_pays_compound_overlay_on_parent_clock_without_waiting_24h(): void
    {
        $this->inWindow();
        $user = $this->user();
        \App\Models\VirtualStakeCompound::query()->create([
            'user_id' => $user->id,
            'stake_index' => 0,
            'usdt_amount' => '50.00',
            'race_amount' => '100',
            'race_price_usdt' => '0.5',
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $service = $this->service();
        $service->stakes = [0 => $this->stake(0)];
        $claim = $service->claim($user, 0, ['from_cron' => true]);

        // Engine clock is 0 days, but 04:00 still pays 1 day on the parent stake + overlay extra.
        $this->assertSame(0, bccomp((string) $claim->usdt_amount, '0.50', 4));
        $this->assertSame(0, bccomp(
            (string) IncomeWalletTransaction::query()
                ->where('user_id', $user->id)
                ->where('type', IncomeWalletTransaction::TYPE_INCOME_CLAIM)
                ->sum('amount'),
            '0.75',
            4,
        ));
        $advance = StakeMintAdvance::query()->where('user_id', $user->id)->where('stake_index', 0)->firstOrFail();
        $this->assertSame(1, (int) $advance->days_outstanding);

        try {
            $service->claim($user, 0, ['from_cron' => true]);
            $this->fail('same-day 04:00 overlay must not pay twice');
        } catch (ValidationException $e) {
            $this->assertStringContainsStringIgnoringCase(
                'nothing to claim',
                (string) collect($e->errors())->flatten()->first(),
            );
        }
    }

    public function test_cron_pays_when_engine_last_reward_is_unix_zero(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service();
        $stake = $this->stake(0);
        $stake['last_reward_at'] = 0;
        $stake['started_at'] = 0;
        $stake['unlock_at'] = 0;
        $service->stakes = [0 => $stake];

        $claim = $service->claim($user, 0, ['from_cron' => true]);

        $this->assertSame(1, (int) $claim->days);
        $this->assertSame(0, bccomp((string) $claim->usdt_amount, '0.50', 4));
        $this->assertSame('03:58', $claim->meta['schedule_anchor'] ?? null);
    }

    public function test_cron_pays_when_engine_clock_is_under_24h(): void
    {
        $this->inWindow();
        $user = $this->user();
        $service = $this->service();
        $stake = $this->stake(0);
        $stake['last_reward_at'] = now()->getTimestamp() - 9 * 3600;
        $service->stakes = [0 => $stake];

        $claim = $service->claim($user, 0, ['from_cron' => true]);

        $this->assertSame(1, (int) $claim->days);
        $this->assertSame(0, bccomp((string) $claim->usdt_amount, '0.50', 4));
    }

    public function test_dashboard_compound_route_debits_virtual_and_is_not_refunded(): void
    {
        $this->inWindow();
        $user = $this->user();
        app(VirtualIncomeWalletService::class)->credit(
            $user,
            IncomeWalletTransaction::TYPE_INCOME_CLAIM,
            '12.50',
            'test:seed',
            'seed-http-compound:'.$user->id,
        );
        \App\Models\BlockchainEngineStake::query()->create([
            'user_id' => $user->id,
            'wallet_address' => self::WALLET,
            'stake_index' => 0,
            'principal_usdt' => '100',
            'staked_race' => '200',
            'stake_price_usdt' => '0.5',
            'lock_seconds' => 180 * 86400,
            'daily_rate_bps' => 50,
            'started_at' => now()->getTimestamp() - 10 * 86400,
            'unlock_at' => now()->getTimestamp() + 170 * 86400,
            'source_type' => 'wallet_race',
            'withdrawn' => false,
            'status' => 'active',
            'tx_hash' => '0x'.str_repeat('e', 64),
            'contract_address' => '0x'.str_repeat('f', 40),
        ]);

        $price = Mockery::mock(\App\Services\Income\RaceCoinService::class);
        $price->shouldReceive('priceUsd')->andReturn(0.5);
        $this->app->instance(\App\Services\Income\RaceCoinService::class, $price);

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->post(route('virtual_income.compound_to_stake'))
            ->assertRedirect(route('dashboard'));

        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user->fresh()), '0', 4));
        $this->assertSame(1, \App\Models\VirtualStakeCompound::query()->where('user_id', $user->id)->count());
        $compound = IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', IncomeWalletTransaction::TYPE_COMPOUND)
            ->first();
        $this->assertNotNull($compound);
        $this->assertSame('virtual_into_last_stake', $compound->metadata['phase'] ?? null);
        $this->assertFalse($compound->metadata['user_wallet_credited'] ?? true);

        $this->travel(25)->hours();
        $this->artisan('income:reconcile-pending-compounds')->assertSuccessful();
        $this->artisan('income:refund-failed-virtual-compounds')->assertSuccessful();
        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user->fresh()), '0', 4));
        $this->assertSame(0, IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', IncomeWalletTransaction::TYPE_COMPOUND_REFUND)
            ->count());
    }

    public function test_compound_accepts_partial_amount_and_keeps_remainder(): void
    {
        $this->inWindow();
        $user = $this->user();
        app(VirtualIncomeWalletService::class)->credit(
            $user,
            IncomeWalletTransaction::TYPE_INCOME_CLAIM,
            '12.5000',
            'test:seed',
            'seed-partial-compound:'.$user->id,
        );
        \App\Models\BlockchainEngineStake::query()->create([
            'user_id' => $user->id,
            'wallet_address' => self::WALLET,
            'stake_index' => 1,
            'principal_usdt' => '300',
            'staked_race' => '400',
            'stake_price_usdt' => '0.5',
            'lock_seconds' => 365 * 86400,
            'daily_rate_bps' => 70,
            'started_at' => now()->getTimestamp() - 5 * 86400,
            'unlock_at' => now()->getTimestamp() + 360 * 86400,
            'source_type' => 'ico',
            'withdrawn' => false,
            'status' => 'active',
            'tx_hash' => '0x'.str_repeat('1', 64),
            'contract_address' => '0x'.str_repeat('2', 40),
        ]);

        $price = Mockery::mock(\App\Services\Income\RaceCoinService::class);
        $price->shouldReceive('priceUsd')->andReturn(0.5);
        $this->app->instance(\App\Services\Income\RaceCoinService::class, $price);

        $this->actingAs($user)
            ->postJson(route('virtual_income.compound_to_stake'), ['amount_usd' => '5.00'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('usdt', '5.0000')
            ->assertJsonPath('stake_index', 1);

        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user->fresh()), '7.5', 4));
        $this->assertSame(0, bccomp(
            (string) \App\Models\VirtualStakeCompound::query()->where('user_id', $user->id)->sum('usdt_amount'),
            '5',
            4,
        ));
    }

    public function test_compound_does_not_debit_without_an_active_stake(): void
    {
        $this->inWindow();
        $user = $this->user();
        app(VirtualIncomeWalletService::class)->credit(
            $user,
            IncomeWalletTransaction::TYPE_INCOME_CLAIM,
            '12.50',
            'test:seed',
            'seed-fail-compound:'.$user->id,
        );

        $price = Mockery::mock(\App\Services\Income\RaceCoinService::class);
        $price->shouldReceive('priceUsd')->andReturn(0.5);
        $this->app->instance(\App\Services\Income\RaceCoinService::class, $price);

        $this->actingAs($user)
            ->postJson(route('virtual_income.compound_to_stake'), ['amount_usd' => '12.50'])
            ->assertStatus(422);

        $this->assertSame(0, bccomp(app(WalletBalanceService::class)->virtualIncomeBalance($user->fresh()), '12.5', 4));
        $this->assertSame(0, IncomeWalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', IncomeWalletTransaction::TYPE_COMPOUND)
            ->count());
    }

    public function test_staking_page_lists_compound_rows_and_folds_principal(): void
    {
        $this->inWindow();
        $user = $this->user();
        \App\Models\BlockchainEngineStake::query()->create([
            'user_id' => $user->id,
            'wallet_address' => self::WALLET,
            'stake_index' => 0,
            'principal_usdt' => '100',
            'staked_race' => '200',
            'stake_price_usdt' => '0.5',
            'lock_seconds' => 180 * 86400,
            'daily_rate_bps' => 50,
            'started_at' => now()->getTimestamp() - 10 * 86400,
            'unlock_at' => now()->getTimestamp() + 170 * 86400,
            'source_type' => 'wallet_race',
            'withdrawn' => false,
            'status' => 'active',
            'tx_hash' => '0x'.str_repeat('a', 64),
            'contract_address' => '0x'.str_repeat('b', 40),
        ]);
        $compound = \App\Models\VirtualStakeCompound::query()->create([
            'user_id' => $user->id,
            'stake_index' => 0,
            'usdt_amount' => '1.0000',
            'race_amount' => '2.00000000',
            'race_price_usdt' => '0.5',
        ]);

        $this->actingAs($user)
            ->get(route('investment'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Investment')
                ->has('stakeCompounds', 1)
                ->where('stakeCompounds.0.id', 'compound-'.$compound->id)
                ->where('stakeCompounds.0.kind', 'compound')
                ->where('stakeCompounds.0.principal_usdt', '1.0000')
                ->where('stakeCompounds.0.stake_index', 0)
                ->where('stakeCompounds.0.lock_days', 180)
                ->has('communityEngineStakes', 1)
                ->where('communityEngineStakes.0.principal_usdt', '101.00000000')
                ->where('communityEngineStakes.0.staked_race', '202.00000000'));
    }

    public function test_compound_skips_flexible_and_uses_last_fixed_stake(): void
    {
        $this->inWindow();
        $user = $this->user();
        app(VirtualIncomeWalletService::class)->credit(
            $user,
            IncomeWalletTransaction::TYPE_INCOME_CLAIM,
            '5.00',
            'test:seed',
            'seed-flex-compound:'.$user->id,
        );
        \App\Models\BlockchainEngineStake::query()->create([
            'user_id' => $user->id,
            'wallet_address' => self::WALLET,
            'stake_index' => 1,
            'principal_usdt' => '100',
            'staked_race' => '200',
            'stake_price_usdt' => '0.5',
            'lock_seconds' => 180 * 86400,
            'daily_rate_bps' => 50,
            'started_at' => now()->getTimestamp() - 20 * 86400,
            'unlock_at' => now()->getTimestamp() + 160 * 86400,
            'source_type' => 'ico',
            'withdrawn' => false,
            'status' => 'active',
            'tx_hash' => '0x'.str_repeat('3', 64),
            'contract_address' => '0x'.str_repeat('4', 40),
        ]);
        \App\Models\BlockchainEngineStake::query()->create([
            'user_id' => $user->id,
            'wallet_address' => self::WALLET,
            'stake_index' => 0,
            'principal_usdt' => '40',
            'staked_race' => '57.6',
            'stake_price_usdt' => '0.5',
            'lock_seconds' => 0,
            'daily_rate_bps' => 40,
            'started_at' => now()->getTimestamp() - 1 * 86400,
            'unlock_at' => now()->getTimestamp(),
            'source_type' => 'wallet_race',
            'withdrawn' => false,
            'status' => 'active',
            'tx_hash' => '0x'.str_repeat('5', 64),
            'contract_address' => '0x'.str_repeat('6', 40),
        ]);

        $price = Mockery::mock(\App\Services\Income\RaceCoinService::class);
        $price->shouldReceive('priceUsd')->andReturn(0.5);
        $this->app->instance(\App\Services\Income\RaceCoinService::class, $price);

        $this->actingAs($user)
            ->postJson(route('virtual_income.compound_to_stake'), ['amount_usd' => '5.00'])
            ->assertOk()
            ->assertJsonPath('stake_index', 1);

        $this->assertSame(1, (int) \App\Models\VirtualStakeCompound::query()->where('user_id', $user->id)->value('stake_index'));
    }

    public function test_compound_rejected_when_only_flexible_stake_exists(): void
    {
        $this->inWindow();
        $user = $this->user();
        app(VirtualIncomeWalletService::class)->credit(
            $user,
            IncomeWalletTransaction::TYPE_INCOME_CLAIM,
            '5.00',
            'test:seed',
            'seed-only-flex:'.$user->id,
        );
        \App\Models\BlockchainEngineStake::query()->create([
            'user_id' => $user->id,
            'wallet_address' => self::WALLET,
            'stake_index' => 0,
            'principal_usdt' => '40',
            'staked_race' => '57.6',
            'stake_price_usdt' => '0.5',
            'lock_seconds' => 0,
            'daily_rate_bps' => 40,
            'started_at' => now()->getTimestamp() - 1 * 86400,
            'unlock_at' => now()->getTimestamp(),
            'source_type' => 'wallet_race',
            'withdrawn' => false,
            'status' => 'active',
            'tx_hash' => '0x'.str_repeat('7', 64),
            'contract_address' => '0x'.str_repeat('8', 40),
        ]);

        $price = Mockery::mock(\App\Services\Income\RaceCoinService::class);
        $price->shouldReceive('priceUsd')->andReturn(0.5);
        $this->app->instance(\App\Services\Income\RaceCoinService::class, $price);

        $this->actingAs($user)
            ->postJson(route('virtual_income.compound_to_stake'), ['amount_usd' => '5.00'])
            ->assertStatus(422);
    }

    public function test_withdrawn_fixed_stake_mints_pending_compound_race(): void
    {
        $this->inWindow();
        $user = $this->user();
        \App\Models\VirtualStakeCompound::query()->create([
            'user_id' => $user->id,
            'stake_index' => 2,
            'usdt_amount' => '1.0000',
            'race_amount' => '2.00000000',
            'race_price_usdt' => '0.5',
            'status' => \App\Models\VirtualStakeCompound::STATUS_PENDING,
        ]);

        $mint = Mockery::mock(RaceCoinDirectMintService::class);
        $mint->shouldReceive('configured')->andReturn(true);
        $mint->shouldReceive('mintToWallet')->once()->with(self::WALLET, '2.00000000')->andReturn([
            'tx_hash' => '0x'.str_repeat('9', 64),
            'to' => self::WALLET,
            'amount_wei' => '2000000000000000000',
            'minter' => '0x'.str_repeat('a', 40),
        ]);
        $this->app->instance(RaceCoinDirectMintService::class, $mint);

        $out = app(\App\Services\Income\VirtualIncomeStakeCompoundService::class)
            ->mintCompoundedOnWithdraw($user, 2);

        $this->assertSame(1, $out['minted']);
        $this->assertSame('0x'.str_repeat('9', 64), $out['tx_hash']);
        $this->assertSame(\App\Models\VirtualStakeCompound::STATUS_MINTED, \App\Models\VirtualStakeCompound::query()->where('user_id', $user->id)->value('status'));
    }

    public function test_withdraw_reconcile_reads_wallet_race_when_engine_has_no_stake(): void
    {
        $this->inWindow();
        $user = $this->user();
        StakeMintAdvance::query()->create([
            'user_id' => $user->id,
            'stake_index' => 0,
            'days_outstanding' => 3,
            'usdt_outstanding' => '14.56000000',
            'last_onchain_reward_at' => 0,
        ]);

        $service = new FakeSplitChainStakeMintClaimService(
            Mockery::mock(RaceCoinDirectMintService::class),
            app(IncomeClaimService::class),
            app(WalletBalanceService::class),
            app(VirtualIncomeWalletService::class),
        );
        $walletRace = $this->stake(0);
        $walletRace['last_reward_at'] = $walletRace['started_at'];
        $service->walletRaceStake = $walletRace;

        $service->reconcileUser($user);

        $advance = StakeMintAdvance::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame(3, (int) $advance->days_outstanding);
        $this->assertSame((int) $walletRace['started_at'], (int) $advance->last_onchain_reward_at);
    }

    public function test_withdraw_reconcile_skips_engine_read_for_indexed_wallet_race_only(): void
    {
        $this->inWindow();
        $user = $this->user();
        StakeMintAdvance::query()->create([
            'user_id' => $user->id,
            'stake_index' => 0,
            'days_outstanding' => 3,
            'usdt_outstanding' => '14.56000000',
            'last_onchain_reward_at' => 0,
        ]);
        \App\Models\BlockchainEngineStake::query()->create([
            'user_id' => $user->id,
            'wallet_address' => self::WALLET,
            'stake_index' => 0,
            'principal_usdt' => '485.37',
            'staked_race' => '734.4',
            'lock_seconds' => 94608000,
            'daily_rate_bps' => 100,
            'started_at' => now()->getTimestamp() - 10 * 86400,
            'unlock_at' => now()->getTimestamp() + 1000 * 86400,
            'source_type' => 'wallet_race',
            'withdrawn' => false,
            'status' => 'active',
            'tx_hash' => '0x'.str_repeat('e', 64),
            'contract_address' => '0x'.str_repeat('b', 40),
        ]);

        $service = new FakeSplitChainStakeMintClaimService(
            Mockery::mock(RaceCoinDirectMintService::class),
            app(IncomeClaimService::class),
            app(WalletBalanceService::class),
            app(VirtualIncomeWalletService::class),
        );

        $service->reconcileUser($user);

        $this->assertSame(3, (int) StakeMintAdvance::query()->where('user_id', $user->id)->value('days_outstanding'));
    }

    public function test_withdraw_reconcile_still_fails_closed_when_engine_stake_cannot_be_read(): void
    {
        $this->inWindow();
        $user = $this->user();
        StakeMintAdvance::query()->create([
            'user_id' => $user->id,
            'stake_index' => 0,
            'days_outstanding' => 1,
            'usdt_outstanding' => '1.00000000',
            'last_onchain_reward_at' => 0,
        ]);

        $service = new FakeSplitChainStakeMintClaimService(
            Mockery::mock(RaceCoinDirectMintService::class),
            app(IncomeClaimService::class),
            app(WalletBalanceService::class),
            app(VirtualIncomeWalletService::class),
        );

        $this->expectException(\RuntimeException::class);
        $service->reconcileUser($user);
    }
}
