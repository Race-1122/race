<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Data;

use App\Models\SiteSetting;
use App\Models\User;
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
        $personalLocked = User::query()
            ->where(function ($q): void {
                $q->where('withdrawals_disabled', true)
                    ->orWhere(function ($q2): void {
                        $q2->whereNotNull('withdrawals_locked_until')
                            ->where('withdrawals_locked_until', '>', now());
                    });
            })
            ->count();

        if ($disabled) {
            $status = __('GLOBAL Permanent OFF — no member can withdraw');
        } elseif ($until !== null) {
            $status = __('GLOBAL timed lock until :when', [
                'when' => $until->timezone(config('app.timezone'))->format('d M Y, h:i A'),
            ]);
        } else {
            $status = __('GLOBAL withdrawals ON');
        }

        if ((bool) config('income.withdrawal_ignore_personal_locks', false)) {
            $status .= ' · '.__('TEST MODE: personal OFF / 24h stake locks are ignored (WITHDRAWAL_IGNORE_PERSONAL_LOCKS=true)');
        } elseif ($personalLocked > 0) {
            $status .= ' · '.__(':count members still have personal OFF/timed stake lock', [
                'count' => $personalLocked,
            ]);
        }

        return [
            'status_preview' => $status,
            'withdrawals_disabled' => $disabled,
            'lock_hours' => null,
            'withdrawals_locked_until' => $rawUntil ? Carbon::parse($rawUntil) : null,
            'clear_timed_lock' => false,
            'clear_all_personal_locks' => true,
        ];
    }

    public function name(): ?string
    {
        return __('Global withdrawals');
    }

    public function description(): ?string
    {
        return __('Turn withdrawals ON/OFF for ALL members. Start now also clears every member personal 24h stake lock so USDT income wallet withdrawals work immediately.');
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
                ->confirm(__('Clear global locks AND every member personal withdrawal lock so all can withdraw now?'))
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
                    ->help(__('Example: 24 = lock everyone for 24 hours from save.')),
                DateTimer::make('withdrawals_locked_until')
                    ->title(__('Timed lock — exact end time (all users)'))
                    ->enableTime()
                    ->allowEmpty()
                    ->help(__('Optional. If set, this end time is used (hours field ignored).')),
                CheckBox::make('clear_timed_lock')
                    ->title(__('Clear global timed lock on save'))
                    ->placeholder(__('Remove global timed lock only'))
                    ->sendTrueOrFalse(),
                CheckBox::make('clear_all_personal_locks')
                    ->title(__('Also clear all personal member locks on save (when turning ON)'))
                    ->placeholder(__('Clears withdrawals_disabled + withdrawals_locked_until for every user'))
                    ->sendTrueOrFalse(),
                Label::make('hint')
                    ->title(__('How users withdraw daily claim'))
                    ->value(__('Daily / auto-claim credits USDT virtual income balance. On Income wallet page use the USDT withdrawal form (not “Bring to my wallet” — that is only for on-chain IncomeHold RACE). Minimum $1. Pending approvals are not changed.')),
            ]),
        ];
    }

    public function startNow(): RedirectResponse
    {
        SiteSetting::set(SiteSetting::KEY_WITHDRAWALS_GLOBALLY_DISABLED, '0');
        SiteSetting::set(SiteSetting::KEY_WITHDRAWALS_GLOBALLY_LOCKED_UNTIL, null);
        $cleared = $this->clearAllPersonalLocks();

        Toast::success(__('Global withdrawals ON. Cleared personal locks on :count members.', [
            'count' => $cleared,
        ]));

        return redirect()->route('platform.data.member-withdrawals');
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'withdrawals_disabled' => ['nullable', 'boolean'],
            'lock_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'withdrawals_locked_until' => ['nullable', 'date'],
            'clear_timed_lock' => ['nullable', 'boolean'],
            'clear_all_personal_locks' => ['nullable', 'boolean'],
        ]);

        $disabled = (bool) ($validated['withdrawals_disabled'] ?? false);
        $clearTimed = (bool) ($validated['clear_timed_lock'] ?? false);
        $clearPersonal = (bool) ($validated['clear_all_personal_locks'] ?? false);
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

        $globallyOpen = ! $disabled && SiteSetting::withdrawalsGloballyLockedUntil() === null;
        $cleared = 0;
        if ($globallyOpen && $clearPersonal) {
            $cleared = $this->clearAllPersonalLocks();
        }

        if ($disabled) {
            Toast::warning(__('Global Permanent OFF saved — all members blocked.'));
        } elseif (SiteSetting::withdrawalsGloballyLockedUntil() !== null) {
            $when = SiteSetting::withdrawalsGloballyLockedUntil()
                ->timezone(config('app.timezone'))
                ->format('d M Y, h:i A');
            Toast::info(__('Global timed lock until :when for all members.', ['when' => $when]));
        } else {
            Toast::info(__('Global withdrawals ON.:extra', [
                'extra' => $cleared > 0
                    ? ' '.__('Cleared personal locks on :count members.', ['count' => $cleared])
                    : '',
            ]));
        }

        return redirect()->route('platform.data.member-withdrawals');
    }

    private function clearAllPersonalLocks(): int
    {
        return User::query()->update([
            'withdrawals_disabled' => false,
            'withdrawals_locked_until' => null,
        ]);
    }
}
