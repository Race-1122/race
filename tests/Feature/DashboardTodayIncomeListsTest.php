<?php

namespace Tests\Feature;

use App\Models\IncomeWalletTransaction;
use App\Models\LedgerEntry;
use App\Models\User;
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
                ->where('summary.income_entries.0.amount_usd', '1.2500'));
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
