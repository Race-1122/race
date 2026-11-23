<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Orchid\Access\Impersonation;
use Orchid\Support\Facades\Toast;

class AdminViewAsUserService
{
    public const SESSION_FLAG = 'admin_view_only';

    public function enter(User $admin, User $target): RedirectResponse
    {
        if (! $admin->hasAccess('platform.data')) {
            abort(403);
        }

        if ((int) $admin->id === (int) $target->id) {
            Toast::warning(__('You are already signed in as that user.'));

            return back();
        }

        if ($this->isStaff($target)) {
            Toast::warning(__('Cannot open an admin account in view-only member login.'));

            return back();
        }

        Impersonation::loginAs($target);
        session([self::SESSION_FLAG => true]);

        Log::info('admin_view_as', [
            'admin_id' => $admin->id,
            'target_id' => $target->id,
            'target_member' => $target->member_number,
        ]);

        return redirect()->route('dashboard')->with(
            'status',
            __('View-only: you can look, not change this account.'),
        );
    }

    public function exit(): RedirectResponse
    {
        session()->forget(self::SESSION_FLAG);

        if (Impersonation::isSwitch()) {
            Impersonation::logout();
        }

        Toast::info(__('Returned to admin.'));

        return redirect()->route('platform.data.user-full-data');
    }

    public function isActive(): bool
    {
        return (bool) session(self::SESSION_FLAG) && Auth::check();
    }

    public function isStaff(User $user): bool
    {
        return $user->hasAccess('platform.index')
            || $user->hasAccess('platform.data')
            || $user->hasAccess('platform.systems.users');
    }
}
