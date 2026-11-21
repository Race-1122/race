<?php

namespace Tests\Feature;

use App\Models\Investment;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Services\Income\IncomeClaimService;
use App\Services\Income\InvestmentRecorder;
use App\Services\Income\LedgerWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateNetworkRoiTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: Investment} */
    private function sponsorMemberStake(?User $sponsor = null, ?User $sponsorOfSponsor = null): array
    {
        $sponsor ??= User::factory()->create([
            'referred_by' => $sponsorOfSponsor?->id,
            'participation_activated_at' => now(),
        ]);
        $member = User::factory()->create([
            'referred_by' => $sponsor->id,
            'participation_activated_at' => now(),
        ]);

        app(LedgerWriter::class)->record(
            $member,
            LedgerEntry::TYPE_WALLET_DEPOSIT,
            '200.00',
            'test',
            null,
            ['note' => 'seed'],
        );

        $inv = app(InvestmentRecorder::class)->record($member, '100.00', 180);

        return [$sponsor, $member, $inv];
    }

    private function claim(User $member): void
    {
        app(IncomeClaimService::class)->claimAccruedIncome($member, ['from_cron' => true, 'ignore_cooldown' => true]);
    }

    private function shareRows(User $sponsor, int $investmentId)
    {
        return LedgerEntry::query()
            ->where('user_id', $sponsor->id)
            ->where('entry_type', LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI)
            ->where('reference_id', $investmentId)
            ->get();
    }

    public function test_roi_accrual_alone_does_not_pay_sponsor(): void
    {
        [$sponsor, , $inv] = $this->sponsorMemberStake();
        $balanceBefore = (string) $sponsor->fresh()->balance_usd;

        $this->artisan('income:pay-roi')->assertSuccessful();

        $this->assertCount(0, $this->shareRows($sponsor, $inv->id));
        $this->assertSame($balanceBefore, (string) $sponsor->fresh()->balance_usd);
    }

    public function test_sponsor_receives_ten_percent_of_direct_roi_when_direct_claims(): void
    {
        [$sponsor, $member, $inv] = $this->sponsorMemberStake();

        $this->artisan('income:pay-roi')->assertSuccessful();
        $this->claim($member);

        // $100 × 0.50% = $0.50 ROI → sponsor 10% = $0.05
        $shares = $this->shareRows($sponsor, $inv->id);
        $this->assertCount(1, $shares);
        $this->assertEqualsWithDelta(0.05, (float) $shares->first()->amount_usd, 0.0001);
        $this->assertSame(1, (int) ($shares->first()->meta['network_level'] ?? 0));
        $this->assertSame('claim', $shares->first()->meta['share_trigger'] ?? null);
    }

    public function test_level_two_upline_does_not_receive_roi_sharing(): void
    {
        $grandparent = User::factory()->create(['participation_activated_at' => now()]);
        [$sponsor, $member, $inv] = $this->sponsorMemberStake(null, $grandparent);

        $this->artisan('income:pay-roi')->assertSuccessful();
        $this->claim($member);

        $this->assertCount(1, $this->shareRows($sponsor, $inv->id));
        $this->assertDatabaseMissing('ledger_entries', [
            'user_id' => $grandparent->id,
            'entry_type' => LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            'reference_id' => $inv->id,
        ]);
    }

    public function test_unlimited_directs_each_pay_sponsor_on_their_claim(): void
    {
        $sponsor = User::factory()->create(['participation_activated_at' => now()]);

        $members = [];
        for ($i = 0; $i < 3; $i++) {
            [, $member] = $this->sponsorMemberStake($sponsor);
            $members[] = $member;
        }

        $this->artisan('income:pay-roi')->assertSuccessful();

        $this->assertEquals(0, LedgerEntry::query()
            ->where('user_id', $sponsor->id)
            ->where('entry_type', LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI)
            ->count());

        foreach ($members as $member) {
            $this->claim($member);
        }

        $total = (float) LedgerEntry::query()
            ->where('user_id', $sponsor->id)
            ->where('entry_type', LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI)
            ->sum('amount_usd');

        // 3 directs × ($100 × 0.50% × 10%) = 3 × 0.05 = 0.15
        $this->assertEqualsWithDelta(0.15, $total, 0.001);
    }

    public function test_second_day_roi_is_shared_once_per_claim(): void
    {
        [$sponsor, $member, $inv] = $this->sponsorMemberStake();

        $this->artisan('income:pay-roi')->assertSuccessful();
        $this->claim($member);
        $this->artisan('income:pay-roi', ['--force-due' => true])->assertSuccessful();
        $this->claim($member);

        $shares = $this->shareRows($sponsor, $inv->id);
        $this->assertCount(2, $shares);
        $this->assertEqualsWithDelta(0.10, (float) $shares->sum('amount_usd'), 0.0001);
    }

    public function test_five_am_cron_pays_sharing_without_claiming_direct_roi(): void
    {
        [$sponsor, $member, $inv] = $this->sponsorMemberStake();

        $this->artisan('income:pay-roi')->assertSuccessful();
        $accrued = (string) $inv->fresh()->accrued_reward_usd;

        $this->artisan('income:pay-roi-sharing')
            ->assertSuccessful()
            ->expectsOutputToContain('paid=1');

        $shares = $this->shareRows($sponsor, $inv->id);
        $this->assertCount(1, $shares);
        $this->assertEqualsWithDelta(0.05, (float) $shares->first()->amount_usd, 0.0001);
        $this->assertSame($accrued, (string) $inv->fresh()->accrued_reward_usd);
        $this->assertDatabaseMissing('ledger_entries', [
            'user_id' => $member->id,
            'entry_type' => LedgerEntry::TYPE_INCOME_CLAIM,
        ]);
    }

    public function test_five_am_cron_is_idempotent(): void
    {
        [$sponsor, $member, $inv] = $this->sponsorMemberStake();

        $this->artisan('income:pay-roi')->assertSuccessful();
        $this->artisan('income:pay-roi-sharing')->assertSuccessful();
        $this->artisan('income:pay-roi-sharing')->assertSuccessful();

        $this->assertCount(1, $this->shareRows($sponsor, $inv->id));
        $this->assertEqualsWithDelta(0.05, (float) $this->shareRows($sponsor, $inv->id)->sum('amount_usd'), 0.0001);
    }

    public function test_claim_after_five_am_cron_does_not_double_share(): void
    {
        [$sponsor, $member, $inv] = $this->sponsorMemberStake();

        $this->artisan('income:pay-roi')->assertSuccessful();
        $this->artisan('income:pay-roi-sharing')->assertSuccessful();
        $this->claim($member);

        $this->assertCount(1, $this->shareRows($sponsor, $inv->id));
        $this->assertEqualsWithDelta(0.05, (float) $this->shareRows($sponsor, $inv->id)->sum('amount_usd'), 0.0001);
    }

    public function test_roi_sharing_command_is_scheduled_at_05_00_ist(): void
    {
        $this->assertSame('05:00', (string) config('income.roi_sharing.release_at'));

        $this->artisan('schedule:list')
            ->expectsOutputToContain('income:pay-roi-sharing')
            ->assertSuccessful();
    }

    public function test_roi_accrued_by_old_cron_is_never_shared_again_on_claim(): void
    {
        [$sponsor, $member, $inv] = $this->sponsorMemberStake();

        $this->artisan('income:pay-roi')->assertSuccessful();

        // Simulate an accrual made before this change (it was already shared at accrual time).
        $accrual = LedgerEntry::query()
            ->where('user_id', $member->id)
            ->where('entry_type', LedgerEntry::TYPE_ROI_ACCRUAL)
            ->where('reference_id', $inv->id)
            ->firstOrFail();
        $meta = $accrual->meta;
        unset($meta['roi_share_on_claim']);
        $accrual->forceFill(['meta' => $meta])->save();

        $this->claim($member);

        $this->assertCount(0, $this->shareRows($sponsor, $inv->id));
    }
}
