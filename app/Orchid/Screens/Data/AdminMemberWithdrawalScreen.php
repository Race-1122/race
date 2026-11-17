<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Data;

use App\Models\User;
use App\Orchid\Concerns\RequiresPlatformDataPermission;
use App\Support\MemberCode;
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

    public function query(Request $request): iterable
    {
        $userId = $request->integer('user_id');
        $user = $userId > 0 ? User::query()->find($userId) : null;

        $preview = __('Enter a user ID and click Load status.');
        if ($user) {
            $parts = [
                MemberCode::format($user->member_number),
                $user->email,
            ];

            if ((bool) $user->withdrawals_disabled) {
                $parts[] = __('permanent OFF');
            } elseif ($user->withdrawals_locked_until !== null && $user->withdrawals_locked_until->isFuture()) {
                $parts[] = __('timed lock until :when', [
                    'when' => $user->withdrawals_locked_until
                        ->timezone(config('app.timezone'))
                        ->format('d M Y, h:i A'),
                ]);
            } else {
                $parts[] = __('withdrawals allowed (ON)');
            }

            $preview = implode(' · ', $parts);
        }

        return [
            'user_id' => $user?->id,
            'withdrawals_disabled' => $user ? (bool) $user->withdrawals_disabled : false,
            'lock_hours' => null,
            'withdrawals_locked_until' => $user?->withdrawals_locked_until,
            'clear_timed_lock' => false,
            'member_preview' => $preview,
        ];
    }

    public function name(): ?string
    {
        return __('Member withdrawals');
    }

    public function description(): ?string
    {
        return __('Turn withdrawals ON/OFF for a member. Optional timed lock (hours or exact end time). Load status first, then save.');
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Load status'))
                ->icon('bs.search')
                ->method('load'),
            Button::make(__('Start withdrawals now'))
                ->icon('bs.unlock')
                ->type(Color::SUCCESS)
                ->confirm(__('Clear permanent OFF and any timed lock so this member can withdraw immediately?'))
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
                Input::make('user_id')
                    ->title(__('Member user ID'))
                    ->type('number')
                    ->required()
                    ->help(__('Find the numeric ID in Users, Team structure, or User Full Data.')),
                Label::make('member_preview')
                    ->title(__('Current status')),
                CheckBox::make('withdrawals_disabled')
                    ->title(__('Permanent OFF'))
                    ->placeholder(__('When checked, member cannot withdraw until you uncheck or tap Start withdrawals now'))
                    ->sendTrueOrFalse(),
                Input::make('lock_hours')
                    ->title(__('Timed lock — hours from now'))
                    ->type('number')
                    ->min(1)
                    ->help(__('Example: 24 = lock for 24 hours from save time. Leave empty to keep existing timed lock (unless you set end time or clear below).')),
                DateTimer::make('withdrawals_locked_until')
                    ->title(__('Timed lock — exact end time'))
                    ->enableTime()
                    ->allowEmpty()
                    ->help(__('Optional. If set, this end time is used (hours field ignored). Past/empty + Clear = no timed lock.')),
                CheckBox::make('clear_timed_lock')
                    ->title(__('Clear timed lock on save'))
                    ->placeholder(__('Remove withdrawals_locked_until only (does not change Permanent OFF)'))
                    ->sendTrueOrFalse(),
                Label::make('hint')
                    ->title(__('Note'))
                    ->value(__('User sees the same 24h-style popup while a future timed lock is active. Permanent OFF shows a support message. Pending withdrawal approvals are not changed.')),
            ]),
        ];
    }

    public function load(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return redirect()->route('platform.data.member-withdrawals', [
            'user_id' => (int) $validated['user_id'],
        ]);
    }

    public function startNow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::query()->findOrFail((int) $validated['user_id']);
        $user->forceFill([
            'withdrawals_disabled' => false,
            'withdrawals_locked_until' => null,
        ])->save();

        Toast::success(__('Withdrawals started (ON) for :member (:email).', [
            'member' => MemberCode::format($user->member_number),
            'email' => $user->email,
        ]));

        return redirect()->route('platform.data.member-withdrawals', [
            'user_id' => $user->id,
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'withdrawals_disabled' => ['nullable', 'boolean'],
            'lock_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'withdrawals_locked_until' => ['nullable', 'date'],
            'clear_timed_lock' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->findOrFail((int) $validated['user_id']);
        $disabled = (bool) ($validated['withdrawals_disabled'] ?? false);
        $clearTimed = (bool) ($validated['clear_timed_lock'] ?? false);
        $lockHours = isset($validated['lock_hours']) && $validated['lock_hours'] !== ''
            ? (int) $validated['lock_hours']
            : null;
        $untilRaw = $validated['withdrawals_locked_until'] ?? null;

        $patch = [
            'withdrawals_disabled' => $disabled,
        ];

        if ($clearTimed) {
            $patch['withdrawals_locked_until'] = null;
        } elseif (is_string($untilRaw) && trim($untilRaw) !== '') {
            $until = Carbon::parse($untilRaw, config('app.timezone'));
            $patch['withdrawals_locked_until'] = $until->isFuture() ? $until : null;
        } elseif ($lockHours !== null && $lockHours > 0) {
            $patch['withdrawals_locked_until'] = now()->addHours($lockHours);
        }

        $user->forceFill($patch)->save();
        $user->refresh();

        if ($disabled) {
            Toast::warning(__('Permanent OFF saved for :member (:email).', [
                'member' => MemberCode::format($user->member_number),
                'email' => $user->email,
            ]));
        } elseif ($user->withdrawals_locked_until !== null && $user->withdrawals_locked_until->isFuture()) {
            Toast::info(__('Timed lock until :when for :member (:email).', [
                'when' => $user->withdrawals_locked_until
                    ->timezone(config('app.timezone'))
                    ->format('d M Y, h:i A'),
                'member' => MemberCode::format($user->member_number),
                'email' => $user->email,
            ]));
        } else {
            Toast::info(__('Withdrawals ON for :member (:email).', [
                'member' => MemberCode::format($user->member_number),
                'email' => $user->email,
            ]));
        }

        return redirect()->route('platform.data.member-withdrawals', [
            'user_id' => $user->id,
        ]);
    }
}
