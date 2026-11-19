<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Data;

use App\Orchid\Concerns\RequiresPlatformDataPermission;
use App\Services\Blockchain\RaceFeeRecipientsReader;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * Read-only: the 4 wallets that receive the RACE 4% transfer fee, with live balances.
 * Balances are read from the chain on every page open (and auto-refresh while the page stays open).
 */
class RaceFeeWalletsScreen extends Screen
{
    use RequiresPlatformDataPermission;

    public function query(): iterable
    {
        return [
            'fee' => app(RaceFeeRecipientsReader::class)->read(),
        ];
    }

    public function name(): ?string
    {
        return __('RACE fee wallets');
    }

    public function description(): ?string
    {
        return __('4 addresses that receive the RACE 4% transfer fee — live on-chain balances.');
    }

    public function commandBar(): iterable
    {
        return [
            Link::make(__('Refresh now'))
                ->icon('bs.arrow-clockwise')
                ->href(route('platform.data.race-fee-wallets')),
        ];
    }

    public function layout(): iterable
    {
        return [
            Layout::view('orchid.race-fee-wallets'),
        ];
    }
}
