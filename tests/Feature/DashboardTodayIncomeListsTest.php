<?php

namespace Tests\Feature;

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
}
