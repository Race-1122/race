<?php

namespace Tests\Feature;

use App\Models\BlockchainEngineStake;
use App\Models\BlockchainParticipation;
use App\Models\CommunityLeadershipDailyHold;
use App\Models\IcoPurchase;
use App\Models\IncomeWalletTransaction;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;
use App\Orchid\Screens\Data\UserFullDataListScreen;
use App\Orchid\Screens\Data\UserFullDataShowScreen;
use App\Services\Income\InvestmentRecorder;
use App\Services\Income\LedgerWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Orchid\Screen\Repository;
use Orchid\Support\Testing\ScreenTesting;
use ReflectionClass;
use Tests\TestCase;

class UserFullDataJanamkundliScreenTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_search_post_hits_list_screen_not_show_user_binding(): void
    {
        $route = Route::getRoutes()->match(Request::create('/admin/data/user-full-data/search', 'POST'));

        $this->assertSame('platform.data.user-full-data', $route->getName());
        $this->assertSame(UserFullDataListScreen::class, $route->getControllerClass());
        $this->assertNull($route->parameter('user'));
    }

    public function test_numeric_user_hits_show_screen(): void
    {
        $route = Route::getRoutes()->match(Request::create('/admin/data/user-full-data/33', 'GET'));

        $this->assertSame('platform.data.user-full-data.show', $route->getName());
        $this->assertSame(UserFullDataShowScreen::class, $route->getControllerClass());
    }

    public function test_empty_orchid_state_does_not_crash_typed_user_property(): void
    {
        $screen = app(UserFullDataShowScreen::class);
        $method = (new ReflectionClass($screen))->getMethod('fillPublicProperty');
        $method->setAccessible(true);
        $method->invoke($screen, new Repository([]));

        $this->assertNull($screen->user);
    }

    public function test_wallet_search_opens_janamkundli_without_500(): void
    {
        $admin = $this->platformAdmin();
        $member = $this->memberWithFullHistory();

        $this->actingAs($admin)
            ->get(route('platform.data.user-full-data'))
            ->assertOk();

        $this->screen('platform.data.user-full-data')
            ->actingAs($admin)
            ->withoutFollowingRedirects()
            ->method('search', ['search' => $member->wallet_address])
            ->assertRedirect(route('platform.data.user-full-data.show', $member->id));

        $this->actingAs($admin)
            ->get(route('platform.data.user-full-data.show', $member->id))
            ->assertOk()
            ->assertSee('Member janamkundli', false)
            ->assertSee($member->wallet_address, false);
    }

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'permissions' => [
                'platform.index' => true,
                'platform.systems' => true,
                'platform.systems.users' => true,
                'platform.data' => true,
            ],
        ]);
    }

    private function memberWithFullHistory(): User
    {
        $from = User::factory()->create(['participation_activated_at' => now()]);
        $member = User::factory()->create([
            'wallet_address' => '0xabc0000000000000000000000000000000000033',
            'participation_activated_at' => now(),
            'id_activated_at' => now()->subDay(),
        ]);

        app(LedgerWriter::class)->record(
            $member,
            LedgerEntry::TYPE_WALLET_DEPOSIT,
            '200.00',
            'test',
            null,
            ['note' => 'seed'],
        );
        app(InvestmentRecorder::class)->record($member, '100.00', 180);
        app(LedgerWriter::class)->record(
            $member,
            LedgerEntry::TYPE_AFFILIATE_NETWORK_ROI,
            '7.50',
            'investment',
            1,
            ['from_user_id' => $from->id, 'share_trigger' => 'claim'],
        );

        Withdrawal::query()->create([
            'user_id' => $member->id,
            'amount_usd' => '50.00',
            'fee_usd' => '5.00',
            'team_reward_usd' => '5.00',
            'admin_fee_usd' => '1.00',
            'net_usd' => '44.00',
            'destination_address' => (string) $member->wallet_address,
            'status' => Withdrawal::STATUS_COMPLETED,
        ]);

        IcoPurchase::query()->create([
            'purchase_id' => 11,
            'user_id' => $member->id,
            'wallet_address' => $member->wallet_address,
            'phase' => 1,
            'usdt_amount' => '50.00',
            'race_amount' => '100.0000',
            'price' => '0.50',
            'tx_hash' => '0x'.str_repeat('a', 64),
            'block_number' => 1,
            'status' => 'held',
        ]);

        BlockchainEngineStake::query()->create([
            'user_id' => $member->id,
            'wallet_address' => $member->wallet_address,
            'stake_index' => 0,
            'principal_usdt' => '50.00',
            'staked_race' => '100.0000',
            'lock_seconds' => 0,
            'daily_rate_bps' => 35,
            'started_at' => time(),
            'source_type' => BlockchainEngineStake::SOURCE_ICO,
            'withdrawn' => false,
            'status' => BlockchainEngineStake::STATUS_ACTIVE,
            'tx_hash' => '0x'.str_repeat('b', 64),
            'contract_address' => '0x'.str_repeat('c', 40),
        ]);

        IncomeWalletTransaction::query()->create([
            'user_id' => $member->id,
            'wallet_address' => $member->wallet_address,
            'type' => IncomeWalletTransaction::TYPE_DAILY_REWARD,
            'amount' => '1.2500',
            'direction' => IncomeWalletTransaction::DIRECTION_CREDIT,
            'status' => IncomeWalletTransaction::STATUS_POSTED,
        ]);

        CommunityLeadershipDailyHold::query()->create([
            'user_id' => $member->id,
            'hold_date' => now()->toDateString(),
            'rank_level' => 2,
            'team_volume_usd' => '1000.00',
            'self_hold_usd' => '100.00',
            'qualified_directs' => 3,
        ]);

        BlockchainParticipation::query()->create([
            'user_id' => $member->id,
            'wallet_address' => $member->wallet_address,
            'tx_hash' => '0x'.str_repeat('d', 64),
            'stake_index' => 1,
            'principal_usdt' => '100.00000000',
            'lock_seconds' => 86400 * 180,
            'daily_rate_bps' => 50,
            'contract_address' => '0x'.str_repeat('e', 40),
            'synced_at' => now(),
        ]);

        return $member->fresh();
    }
}
