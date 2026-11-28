<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Data;

use App\Models\User;
use App\Orchid\Concerns\RequiresPlatformDataPermission;
use App\Services\Income\ReferralTree;
use App\Support\MemberCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Label;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class AdminChangeSponsorScreen extends Screen
{
    use RequiresPlatformDataPermission;

    public function query(Request $request): iterable
    {
        $userId = $request->integer('user_id');
        $sponsorId = $request->integer('new_sponsor_user_id');
        $user = $userId > 0 ? User::query()->with('referrer:id,name,email,member_number')->find($userId) : null;
        $sponsor = $sponsorId > 0 ? User::query()->find($sponsorId) : null;

        $memberPreview = __('Enter member user ID and click Load.');
        if ($user) {
            $current = $user->referrer
                ? MemberCode::format($user->referrer->member_number).' · '.$user->referrer->email
                : __('none');
            $memberPreview = MemberCode::format($user->member_number).' · '.$user->email.' · '.__('current sponsor').': '.$current;
        }

        $sponsorPreview = __('Enter new sponsor user ID (optional until Save).');
        if ($sponsor) {
            $sponsorPreview = MemberCode::format($sponsor->member_number).' · '.$sponsor->email.($sponsor->is_blocked ? ' · '.__('BLOCKED') : '');
        }

        return [
            'user_id' => $user?->id,
            'new_sponsor_user_id' => $sponsor?->id,
            'member_preview' => $memberPreview,
            'sponsor_preview' => $sponsorPreview,
        ];
    }

    public function name(): ?string
    {
        return __('Change team structure');
    }

    public function description(): ?string
    {
        return __('Move a member under any other sponsor (updates referred_by). Blocks cycles — cannot place someone under their own downline. Laravel tree only; on-chain Engine referrer is unchanged.');
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Button::make(__('Load'))
                ->icon('bs.search')
                ->method('load'),
            Button::make(__('Save new sponsor'))
                ->icon('bs.check-circle')
                ->confirm(__('Move this member under the new sponsor? Past income is not recalculated.'))
                ->method('save'),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::rows([
                Input::make('user_id')
                    ->title(__('Member user ID (who to move)'))
                    ->type('number')
                    ->required()
                    ->help(__('Numeric users.id — from Team structure or User Full Data.')),
                Label::make('member_preview')
                    ->title(__('Member')),
                Input::make('new_sponsor_user_id')
                    ->title(__('New sponsor user ID (place under)'))
                    ->type('number')
                    ->required()
                    ->help(__('This becomes the member\'s direct upline (referred_by).')),
                Label::make('sponsor_preview')
                    ->title(__('New sponsor')),
                Label::make('hint')
                    ->title(__('Safety'))
                    ->value(__('Cannot set self as sponsor. Cannot set a downline member as sponsor (cycle). Does not rewrite historical ledger or on-chain register().')),
            ]),
        ];
    }

    public function load(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'new_sponsor_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $params = ['user_id' => (int) $validated['user_id']];
        if (! empty($validated['new_sponsor_user_id'])) {
            $params['new_sponsor_user_id'] = (int) $validated['new_sponsor_user_id'];
        }

        return redirect()->route('platform.data.change-sponsor', $params);
    }

    public function save(Request $request, ReferralTree $tree): RedirectResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'new_sponsor_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $userId = (int) $validated['user_id'];
        $sponsorId = (int) $validated['new_sponsor_user_id'];

        if ($userId === $sponsorId) {
            throw ValidationException::withMessages([
                'new_sponsor_user_id' => __('Member cannot be their own sponsor.'),
            ]);
        }

        $user = User::query()->findOrFail($userId);
        $sponsor = User::query()->findOrFail($sponsorId);

        $downlineIds = $tree->allReferralDescendantIds($userId);
        if (in_array($sponsorId, $downlineIds, true)) {
            throw ValidationException::withMessages([
                'new_sponsor_user_id' => __('Cannot place member under their own downline (cycle).'),
            ]);
        }

        $oldSponsorId = $user->referred_by;
        $user->forceFill([
            'referred_by' => $sponsor->id,
            'joined_with_code' => $sponsor->referral_code ?: $user->joined_with_code,
        ])->save();

        Toast::success(__('Moved :member under :sponsor (was sponsor id :old).', [
            'member' => MemberCode::format($user->member_number),
            'sponsor' => MemberCode::format($sponsor->member_number),
            'old' => $oldSponsorId !== null ? (string) $oldSponsorId : __('none'),
        ]));

        return redirect()->route('platform.data.change-sponsor', [
            'user_id' => $user->id,
            'new_sponsor_user_id' => $sponsor->id,
        ]);
    }
}
