<?php

namespace Tests\Feature;

use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\Admin\UserDossierService;
use App\Services\Income\InvestmentRecorder;
use App\Services\Income\LedgerWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDossierServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_totals_stakes_income_and_petrol_fees(): void
    {
        $from = User::factory()->create(['participation_activated_at' => now()]);
        $user = User::factory()->create([
            'wallet_address' => '0xabc0000000000000000000000000000000000001',
            'participation_activated_at' => now(),
        ]);

        app(LedgerWriter::class)->record(
            $user,
            LedgerEntry::TYPE_WALLET_DEPOSIT,
            '200.00',
            'test',
            null,
            ['note' => 'seed'],
        );
        app(InvestmentRecorder::class)->record($user, '100.00', 180);

        app(LedgerWriter::class)->record(
            $user,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            '7.50',
            'investment',
            1,
            ['from_user_id' => $from->id, 'share_trigger' => 'claim'],
        );

        Withdrawal::query()->create([
            'user_id' => $user->id,
            'amount_usd' => '50.00',
            'fee_usd' => '5.00',
            'team_reward_usd' => '5.00',
            'admin_fee_usd' => '1.00',
            'net_usd' => '44.00',
            'destination_address' => (string) $user->wallet_address,
            'status' => Withdrawal::STATUS_COMPLETED,
        ]);

        $d = app(UserDossierService::class)->summarize($user);

        $this->assertSame('100.00', $d['laravel_invested_usd']);
        $this->assertSame(1, $d['laravel_stake_count']);
        $this->assertSame('200.00', $d['deposit_usd']);
        $this->assertSame('50.00', $d['petrol_gross_usd']);
        $this->assertSame('5.00', $d['team_10_percent_usd']);
        $this->assertSame('1.00', $d['admin_fee_usd']);
        $this->assertStringContainsString('Yes', $d['team_10_percent_split']);
        $this->assertSame('7.50', $d['income_usd']);
        $this->assertSame(1, $d['income_count']);
        $this->assertNotEmpty($d['income_from']);
        $this->assertSame($from->id, $d['income_from'][0]['user_id']);
    }
}
