<?php

namespace Tests\Feature;

use App\Models\IncomeWalletTransaction;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Income\LedgerWriter;
use App\Services\Income\RaceCoinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardTodayIncomeListsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_income_entries_are_ist_today_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 09:00:00', 'Asia/Kolkata'));

        $user = User::factory()->create();
        $writer = app(LedgerWriter::class);

        $today = $writer->record(
            $user,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            '1.2500',
            'investment',
            1,
            ['framework' => 'roi_sharing'],
        );
        $yesterday = $writer->record(
            $user,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            '9.9900',
            'investment',
            2,
            ['framework' => 'roi_sharing'],
        );
        $yesterday->forceFill([
            'created_at' => Carbon::parse('2026-11-21 05:00:00', 'Asia/Kolkata'),
        ])->save();

        $this->mock(RaceCoinService::class, function ($mock): void {
            $mock->shouldReceive('currentBalance')->andReturn('0');
            $mock->shouldReceive('priceUsd')->andReturn(0.55);
            $mock->shouldReceive('formattedPriceUsd')->andReturn('0.5500');
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('summary.income_entries', 1)
                ->where('summary.income_entries.0.id', $today->id)
                ->where('summary.income_entries.0.income_key', 'affiliate_network_roi')
                ->where('summary.income_entries.0.amount_usd', '1.2500'));
    }

    public function test_daily_earning_card_sums_all_today_income_types(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00', 'Asia/Kolkata'));

        $user = User::factory()->create();
        $this->postIncomeClaim($user, '27.5000');
        IncomeWalletTransaction::query()->create([
            'user_id' => $user->id,
            'wallet_address' => $user->wallet_address,
            'type' => IncomeWalletTransaction::TYPE_LEVEL_INCOME,
            'source_reference' => 'test:roi-share',
            'amount' => '2.9800',
            'balance_before_usd' => '27.5000',
            'balance_after_usd' => '30.4800',
            'asset' => 'USDT',
            'direction' => IncomeWalletTransaction::DIRECTION_CREDIT,
            'status' => IncomeWalletTransaction::STATUS_POSTED,
            'idempotency_key' => 'test-today-roi-'.$user->id,
            'metadata' => ['framework' => 'roi_sharing'],
        ]);
        $old = IncomeWalletTransaction::query()->create([
            'user_id' => $user->id,
            'wallet_address' => $user->wallet_address,
            'type' => IncomeWalletTransaction::TYPE_REFERRAL_INCOME,
            'source_reference' => 'test:old-ref',
            'amount' => '9.9900',
            'balance_before_usd' => '0.0000',
            'balance_after_usd' => '9.9900',
            'asset' => 'USDT',
            'direction' => IncomeWalletTransaction::DIRECTION_CREDIT,
            'status' => IncomeWalletTransaction::STATUS_POSTED,
            'idempotency_key' => 'test-old-ref-'.$user->id,
            'metadata' => [],
        ]);
        $old->forceFill([
            'created_at' => Carbon::parse('2026-11-24 10:00:00', 'Asia/Kolkata')
                ->timezone(config('app.timezone')),
        ])->save();

        $this->mock(RaceCoinService::class, function ($mock): void {
            $mock->shouldReceive('currentBalance')->andReturn('0');
            $mock->shouldReceive('priceUsd')->andReturn(0.55);
            $mock->shouldReceive('formattedPriceUsd')->andReturn('0.5500');
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('summary.today_income_usd', '30.48')
                ->where('summary.today_income_count', 2));
    }

    public function test_transactions_page_defaults_to_all_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 09:00:00', 'Asia/Kolkata'));

        $user = User::factory()->create();
        $writer = app(LedgerWriter::class);

        $writer->record(
            $user,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            '1.2500',
            'investment',
            1,
            ['framework' => 'roi_sharing'],
        );
        $old = $writer->record(
            $user,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            '9.9900',
            'investment',
            2,
            ['framework' => 'roi_sharing'],
        );
        $old->forceFill([
            'created_at' => Carbon::parse('2026-11-21 05:00:00', 'Asia/Kolkata'),
        ])->save();

        $this->actingAs($user)
            ->get(route('transactions', ['income' => 'affiliate_network_roi']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transactions')
                ->where('filter_date', 'all')
                ->has('entries', 2));
    }

    public function test_section_ledger_all_and_today_exclude_other_income_types(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 09:00:00', 'Asia/Kolkata'));

        $user = User::factory()->create();
        $writer = app(LedgerWriter::class);

        $todayRoi = $writer->record(
            $user,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            '1.2500',
            'investment',
            1,
            ['framework' => 'roi_sharing'],
        );
        $oldRoi = $writer->record(
            $user,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            '9.9900',
            'investment',
            2,
            ['framework' => 'roi_sharing'],
        );
        $oldRoi->forceFill([
            'created_at' => Carbon::parse('2026-11-21 05:00:00', 'Asia/Kolkata'),
        ])->save();

        $writer->record(
            $user,
            LedgerEntry::TYPE_COMMUNITY_REFERRAL,
            '5.0000',
            'investment',
            3,
            ['level' => 1],
        );
        $writer->record(
            $user,
            LedgerEntry::TYPE_INCOME_CLAIM,
            '3.0000',
            'investment',
            4,
            [],
        );

        $this->actingAs($user)
            ->get(route('transactions', ['income' => 'affiliate_network_roi', 'date' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transactions')
                ->where('filter_income_key', 'affiliate_network_roi')
                ->where('filter_date', 'all')
                ->has('entries', 2)
                ->where('entries.0.entry_type', LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI)
                ->where('entries.1.entry_type', LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI));

        $this->actingAs($user)
            ->get(route('transactions', [
                'income' => 'affiliate_network_roi',
                'date' => Carbon::today()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transactions')
                ->where('filter_income_key', 'affiliate_network_roi')
                ->has('entries', 1)
                ->where('entries.0.id', $todayRoi->id)
                ->where('entries.0.entry_type', LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI));
    }

    public function test_dashboard_shows_todays_compound_transactions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 09:00:00', 'Asia/Kolkata'));

        $user = User::factory()->create();
        $today = $this->postCompound($user, '25.0000');
        $yesterday = $this->postCompound($user, '10.0000');
        $yesterday->forceFill([
            'created_at' => Carbon::parse('2026-11-21 22:01:00', 'Asia/Kolkata')
                ->timezone(config('app.timezone')),
        ])->save();

        $this->mock(RaceCoinService::class, function ($mock): void {
            $mock->shouldReceive('currentBalance')->andReturn('0');
            $mock->shouldReceive('priceUsd')->andReturn(0.55);
            $mock->shouldReceive('formattedPriceUsd')->andReturn('0.5500');
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('summary.daily_claims.compounds', 1)
                ->where('summary.daily_claims.compounds.0.id', 'compound-'.$today->id)
                ->where('summary.daily_claims.compounds.0.amount_usd', '-25.0000'));
    }

    public function test_transactions_page_includes_compound_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 09:00:00', 'Asia/Kolkata'));

        $user = User::factory()->create();
        $today = $this->postCompound($user, '25.0000');
        $yesterday = $this->postCompound($user, '10.0000');
        $yesterday->forceFill([
            'created_at' => Carbon::parse('2026-11-21 22:01:00', 'Asia/Kolkata')
                ->timezone(config('app.timezone')),
        ])->save();

        $this->actingAs($user)
            ->get(route('transactions', ['income' => 'participation_rewards']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transactions')
                ->has('entries', 2)
                ->where('entries.0.id', 'compound-'.$today->id)
                ->where('entries.0.entry_type', 'compound')
                ->where('entries.0.amount_usd', '-25.0000')
                ->where('entries.1.id', 'compound-'.$yesterday->id));
    }

    public function test_staking_reward_ledger_includes_virtual_daily_claims(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-22 09:00:00', 'Asia/Kolkata'));

        $user = User::factory()->create();
        $writer = app(LedgerWriter::class);

        $oldLedger = $writer->record(
            $user,
            LedgerEntry::TYPE_ROI_DAILY,
            '20.4461',
            'investment',
            21,
            [],
        );
        $oldLedger->forceFill([
            'created_at' => Carbon::parse('2026-11-17 09:41:00', 'Asia/Kolkata'),
        ])->save();

        $todayClaim = $this->postIncomeClaim($user, '1.2500');
        $oldClaim = $this->postIncomeClaim($user, '0.5000');
        $oldClaim->forceFill([
            'created_at' => Carbon::parse('2026-11-21 22:30:00', 'Asia/Kolkata')
                ->timezone(config('app.timezone')),
        ])->save();

        $writer->record(
            $user,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            '9.9900',
            'investment',
            2,
            ['framework' => 'roi_sharing'],
        );

        $this->actingAs($user)
            ->get(route('transactions', ['income' => 'participation_rewards', 'date' => 'all']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transactions')
                ->where('filter_income_key', 'participation_rewards')
                ->has('entries', 3)
                ->where('entries.0.id', 'iwt-claim-'.$todayClaim->id)
                ->where('entries.0.entry_type', IncomeWalletTransaction::TYPE_INCOME_CLAIM)
                ->where('entries.0.amount_usd', '1.2500')
                ->where('filtered_earnings_usd', '22.20'));

        $this->actingAs($user)
            ->get(route('transactions', [
                'income' => 'participation_rewards',
                'date' => Carbon::today()->toDateString(),
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transactions')
                ->has('entries', 1)
                ->where('entries.0.id', 'iwt-claim-'.$todayClaim->id)
                ->where('day_total_usd', '1.25'));
    }

    public function test_staking_today_includes_ist_0400_claims_stored_on_previous_utc_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-25 14:26:00', 'Asia/Kolkata'));

        $user = User::factory()->create();
        $claim = $this->postIncomeClaim($user, '20.4461');
        $claim->forceFill([
            'created_at' => Carbon::parse('2026-11-25 04:00:18', 'Asia/Kolkata')
                ->timezone(config('app.timezone')),
        ])->save();

        $this->actingAs($user)
            ->get(route('transactions', [
                'income' => 'participation_rewards',
                'date' => '2026-11-25',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Transactions')
                ->where('filter_date_label', 'Today')
                ->has('entries', 1)
                ->where('entries.0.id', 'iwt-claim-'.$claim->id)
                ->where('entries.0.amount_usd', '20.4461')
                ->where('day_total_usd', '20.45'));
    }

    public function test_dashboard_shows_completed_withdrawal_totals(): void
    {
        $user = User::factory()->create();
        Withdrawal::query()->create([
            'user_id' => $user->id,
            'amount_usd' => '14.56',
            'fee_usd' => '1.46',
            'team_reward_usd' => '1.46',
            'admin_fee_usd' => '1.00',
            'net_usd' => '13.10',
            'race_amount' => '24.67060000',
            'destination_address' => '0x9ff1cd17834078ffb730b9ec848fc52963f17f08',
            'status' => Withdrawal::STATUS_COMPLETED,
        ]);
        Withdrawal::query()->create([
            'user_id' => $user->id,
            'amount_usd' => '10.00',
            'fee_usd' => '1.00',
            'net_usd' => '9.00',
            'race_amount' => '10.00000000',
            'destination_address' => '0x9ff1cd17834078ffb730b9ec848fc52963f17f08',
            'status' => Withdrawal::STATUS_PENDING,
        ]);

        $this->mock(RaceCoinService::class, function ($mock): void {
            $mock->shouldReceive('currentBalance')->andReturn('0');
            $mock->shouldReceive('priceUsd')->andReturn(0.55);
            $mock->shouldReceive('formattedPriceUsd')->andReturn('0.5500');
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('summary.total_withdrawn_usd', '14.56')
                ->where('summary.total_withdrawn_race', '24.6706')
                ->where('summary.total_withdrawn_count', 1));
    }

    public function test_withdrawal_history_includes_usdt_deducted_and_race_received(): void
    {
        $user = User::factory()->create();
        Withdrawal::query()->create([
            'user_id' => $user->id,
            'amount_usd' => '14.56',
            'fee_usd' => '1.46',
            'team_reward_usd' => '1.46',
            'admin_fee_usd' => '1.00',
            'net_usd' => '13.10',
            'race_amount' => '24.67060000',
            'race_tx_hash' => '0xabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabca',
            'destination_address' => '0x9ff1cd17834078ffb730b9ec848fc52963f17f08',
            'status' => Withdrawal::STATUS_COMPLETED,
        ]);

        $this->mock(RaceCoinService::class, function ($mock): void {
            $mock->shouldReceive('currentBalance')->andReturn('0');
            $mock->shouldReceive('priceUsd')->andReturn(0.55);
            $mock->shouldReceive('formattedPriceUsd')->andReturn('0.5500');
        });

        $this->actingAs($user)
            ->get(route('withdrawal'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Withdrawal')
                ->has('withdrawals', 1)
                ->where('withdrawals.0.amount_usd', '14.56')
                ->where('withdrawals.0.race_amount', '24.67060000')
                ->where('withdrawals.0.race_tx_url', 'https://bscscan.com/tx/0xabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabcabca'));
    }

    public function test_total_reward_uses_wallet_earned_not_extra_ledger(): void
    {
        $user = User::factory()->create();
        $this->postIncomeClaim($user, '50.0000');
        IncomeWalletTransaction::query()->create([
            'user_id' => $user->id,
            'wallet_address' => $user->wallet_address,
            'type' => IncomeWalletTransaction::TYPE_REFERRAL_INCOME,
            'source_reference' => 'test:referral',
            'amount' => '100.0000',
            'balance_before_usd' => '50.0000',
            'balance_after_usd' => '150.0000',
            'asset' => 'USDT',
            'direction' => IncomeWalletTransaction::DIRECTION_CREDIT,
            'status' => IncomeWalletTransaction::STATUS_POSTED,
            'idempotency_key' => 'test-referral-'.$user->id,
            'metadata' => [],
        ]);
        LedgerEntry::query()->create([
            'user_id' => $user->id,
            'entry_type' => LedgerEntry::TYPE_COMMUNITY_LEADERSHIP_SKIP,
            'amount_usd' => '6.7600',
            'balance_after_usd' => '6.7600',
            'reference_type' => 'test',
            'reference_id' => 1,
            'meta' => [],
        ]);

        $this->mock(RaceCoinService::class, function ($mock): void {
            $mock->shouldReceive('currentBalance')->andReturn('0');
            $mock->shouldReceive('priceUsd')->andReturn(0.55);
            $mock->shouldReceive('formattedPriceUsd')->andReturn('0.5500');
        });

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->where('summary.total_income_usd', '150.00')
                ->where('summary.income_buckets.other_usd', '100.00')
                ->where('summary.income_buckets.daily_claim_usd', '50.00'));
    }

    private function postIncomeClaim(User $user, string $amount): IncomeWalletTransaction
    {
        return IncomeWalletTransaction::query()->create([
            'user_id' => $user->id,
            'wallet_address' => $user->wallet_address,
            'type' => IncomeWalletTransaction::TYPE_INCOME_CLAIM,
            'source_reference' => 'stake_mint_claim:test',
            'amount' => $amount,
            'balance_before_usd' => '0.0000',
            'balance_after_usd' => $amount,
            'asset' => 'USDT',
            'direction' => IncomeWalletTransaction::DIRECTION_CREDIT,
            'status' => IncomeWalletTransaction::STATUS_POSTED,
            'idempotency_key' => 'test-claim-'.$user->id.'-'.$amount.'-'.uniqid(),
            'metadata' => [
                'stake_index' => 1,
                'race_amount' => '2.000000',
                'from_cron' => true,
            ],
        ]);
    }

    private function postCompound(User $user, string $amount): IncomeWalletTransaction
    {
        return IncomeWalletTransaction::query()->create([
            'user_id' => $user->id,
            'wallet_address' => $user->wallet_address,
            'type' => IncomeWalletTransaction::TYPE_COMPOUND,
            'source_reference' => 'engine_stake:0',
            'amount' => $amount,
            'balance_before_usd' => $amount,
            'balance_after_usd' => '0.0000',
            'asset' => 'USDT',
            'direction' => IncomeWalletTransaction::DIRECTION_DEBIT,
            'status' => IncomeWalletTransaction::STATUS_POSTED,
            'idempotency_key' => 'test-compound-'.$user->id.'-'.$amount.'-'.uniqid(),
            'metadata' => [
                'stake_index' => 0,
                'race_amount' => '10.000000',
            ],
        ]);
    }
}
