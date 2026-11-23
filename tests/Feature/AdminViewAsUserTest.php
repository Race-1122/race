<?php

namespace Tests\Feature;

use App\Models\User;
use App\Orchid\Screens\Data\UserFullDataListScreen;
use App\Services\Admin\AdminViewAsUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Orchid\Support\Testing\ScreenTesting;
use Tests\TestCase;

class AdminViewAsUserTest extends TestCase
{
    use RefreshDatabase;
    use ScreenTesting;

    public function test_admin_can_open_member_in_view_only_login(): void
    {
        $admin = $this->platformAdmin();
        $member = User::factory()->create(['permissions' => []]);

        $this->actingAs($admin)
            ->get(route('platform.data.user-full-data'))
            ->assertOk()
            ->assertSee('Login', false);

        $this->screen('platform.data.user-full-data')
            ->actingAs($admin)
            ->withoutFollowingRedirects()
            ->method('loginAsViewOnly', ['id' => $member->id])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($member);
        $this->assertTrue((bool) session(AdminViewAsUserService::SESSION_FLAG));
    }

    public function test_view_only_admin_can_read_but_cannot_change(): void
    {
        $admin = $this->platformAdmin();
        $member = User::factory()->create(['permissions' => []]);

        $this->actingAs($admin);
        $this->screen('platform.data.user-full-data')
            ->withoutFollowingRedirects()
            ->method('loginAsViewOnly', ['id' => $member->id]);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('investment'))->assertOk();

        $this->post(route('virtual_income.compound_to_stake'), ['amount_usd' => '1.00'])
            ->assertForbidden();
        $this->post(route('investments.store'), ['amount_usd' => '50'])
            ->assertForbidden();
    }

    public function test_view_only_exit_returns_to_admin(): void
    {
        $admin = $this->platformAdmin();
        $member = User::factory()->create(['permissions' => []]);

        $this->actingAs($admin);
        $this->screen('platform.data.user-full-data')
            ->withoutFollowingRedirects()
            ->method('loginAsViewOnly', ['id' => $member->id]);

        $this->post(route('admin.view_as.exit'))
            ->assertRedirect(route('platform.data.user-full-data'));

        $this->assertAuthenticatedAs($admin);
        $this->assertFalse((bool) session(AdminViewAsUserService::SESSION_FLAG));
    }

    public function test_cannot_view_as_another_admin(): void
    {
        $admin = $this->platformAdmin();
        $other = $this->platformAdmin();

        $this->actingAs($admin);
        $this->screen('platform.data.user-full-data')
            ->withoutFollowingRedirects()
            ->method('loginAsViewOnly', ['id' => $other->id])
            ->assertRedirect();

        $this->assertAuthenticatedAs($admin);
        $this->assertFalse((bool) session(AdminViewAsUserService::SESSION_FLAG));
    }

    public function test_member_cannot_use_admin_view_as_screen(): void
    {
        $member = User::factory()->create(['permissions' => []]);
        $target = User::factory()->create(['permissions' => []]);

        $this->actingAs($member)
            ->get(route('platform.data.user-full-data'))
            ->assertForbidden();

        $this->assertTrue(Route::has('admin.view_as.exit'));
        $this->assertSame(UserFullDataListScreen::class, Route::getRoutes()->getByName('platform.data.user-full-data')->getControllerClass());
        $this->assertNotSame($target->id, $member->id);
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
}
