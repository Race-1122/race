<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Data;

use App\Models\SiteSetting;
use App\Orchid\Concerns\RequiresPlatformDataPermission;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\DateTimer;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Label;
use Orchid\Screen\Screen;
use Orchid\Support\Color;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class AdminMemberWithdrawalScreen extends Screen
{
    use RequiresPlatformDataPermission;

    public function query(): iterable
    {
        $disabled = SiteSetting::withdrawalsGloballyDisabled();
        $until = SiteSetting::withdrawalsGloballyLockedUntil();
        $rawUntil = SiteSetting::get(SiteSetting::KEY_WITHDRAWALS_GLOBALLY_LOCKED_UNTIL);

        if ($disabled) {
            $status = __('GLOBAL Permanent OFF — no member can withdraw');
        } elseif ($until !== null) {
            $status = __('GLOBAL timed lock until :when', [
                'when' => $until->timezone(config('app.timezone'))->format('d M Y, h:i A'),
            ]);
        } else {
            $status = __('GLOBAL withdrawals ON — all members can withdraw (unless per-user stake lock)');
        }

        return [
            'status_preview' => $status,
            'withdrawals_disabled' => $disabled,
            'lock_hours' => null,
            'withdrawals_locked_until' => $rawUntil ? Carbon::parse($rawUntil) : null,
            'clear_timed_lock' => false,
        ];
    }

    public function name(): ?string
    {
        return __('Global withdrawals');
    }

    public function description(): ?string
    {
        return __('Turn withdrawals ON/OFF for ALL members at once. Timed lock or permanent OFF applies platform-wide.');
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Start withdrawals now (all)'))
                ->icon('bs.unlock')
                ->type(Color::SUCCESS)
                ->confirm(__('Clear global Permanent OFF and any global timed lock so every member can withdraw (per-user stake locks may still apply)?'))
                ->method('startNow'),
            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                Label::make('status_preview')
                    ->title(__('Current global status')),
                CheckBox::make('withdrawals_disabled')
                    ->title(__('Permanent OFF (all users)'))
                    ->placeholder(__('When checked, NO member can withdraw until you uncheck or tap Start withdrawals now'))
                    ->sendTrueOrFalse(),
                Input::make('lock_hours')
                    ->title(__('Timed lock — hours from now (all users)'))
                    ->type('number')
                    ->min(1)
                    ->help(__('Example: 24 = lock everyone for 24 hours from save. Leave empty to keep existing timed lock unless you set end time or clear below.')),
                DateTimer::make('withdrawals_locked_until')
                    ->title(__('Timed lock — exact end time (all users)'))
                    ->enableTime()
                    ->allowEmpty()
                    ->help(__('Optional. If set, this end time is used (hours field ignored).')),
                CheckBox::make('clear_timed_lock')
                    ->title(__('Clear timed lock on save'))
                    ->placeholder(__('Remove global timed lock only (does not change Permanent OFF)'))
                    ->sendTrueOrFalse(),
                Label::make('hint')
                    ->title(__('Note'))
                    ->value(__('This applies to ALL members. User sees a global lock popup. Individual 24h stake locks still work after global unlock. Pending withdrawal approvals are not changed.')),
            ]),
        ];
    }

    public function startNow(): RedirectResponse
    {
        SiteSetting::set(SiteSetting::KEY_WITHDRAWALS_GLOBALLY_DISABLED, '0');
        SiteSetting::set(SiteSetting::KEY_WITHDRAWALS_GLOBALLY_LOCKED_UNTIL, null);

        Toast::success(__('Global withdrawals started (ON) for all members.'));

        return redirect()->route('platform.data.member-withdrawals');
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'withdrawals_disabled' => ['nullable', 'boolean'],
            'lock_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'withdrawals_locked_until' => ['nullable', 'date'],
            'clear_timed_lock' => ['nullable', 'boolean'],
        ]);

        $disabled = (bool) ($validated['withdrawals_disabled'] ?? false);
        $clearTimed = (bool) ($validated['clear_timed_lock'] ?? false);
        $lockHours = isset($validated['lock_hours']) && $validated['lock_hours'] !== '' && $validated['lock_hours'] !== null
            ? (int) $validated['lock_hours']
            : null;
        $untilRaw = $validated['withdrawals_locked_until'] ?? null;

        SiteSetting::set(
            SiteSetting::KEY_WITHDRAWALS_GLOBALLY_DISABLED,
            $disabled ? '1' : '0',
        );

        if ($clearTimed) {
            SiteSetting::set(SiteSetting::KEY_WITHDRAWALS_GLOBALLY_LOCKED_UNTIL, null);
        } elseif (is_string($untilRaw) && trim($untilRaw) !== '') {
            $until = Carbon::parse($untilRaw, config('app.timezone'));
            SiteSetting::set(
                SiteSetting::KEY_WITHDRAWALS_GLOBALLY_LOCKED_UNTIL,
                $until->isFuture() ? $until->toIso8601String() : null,
            );
        } elseif ($lockHours !== null && $lockHours > 0) {
            SiteSetting::set(
                SiteSetting::KEY_WITHDRAWALS_GLOBALLY_LOCKED_UNTIL,
                now()->addHours($lockHours)->toIso8601String(),
            );
        }

        if ($disabled) {
            Toast::warning(__('Global Permanent OFF saved — all members blocked.'));
        } elseif (SiteSetting::withdrawalsGloballyLockedUntil() !== null) {
            $when = SiteSetting::withdrawalsGloballyLockedUntil()
                ->timezone(config('app.timezone'))
                ->format('d M Y, h:i A');
            Toast::info(__('Global timed lock until :when for all members.', ['when' => $when]));
        } else {
            Toast::info(__('Global withdrawals ON for all members.'));
        }

        return redirect()->route('platform.data.member-withdrawals');
    }
}
