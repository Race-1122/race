<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Data;

use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class UserFullDataSearchLayout extends Rows
{
    protected function fields(): iterable
    {
        return [
            Input::make('search')
                ->title(__('Wallet or member search'))
                ->value(request()->query('search'))
                ->placeholder(__('Paste 0x wallet address — or ID, member code, name, email, referral code'))
                ->help(__('Exact 0x wallet paste opens that user’s janamkundli immediately. Other terms list matches.')),
        ];
    }
}
