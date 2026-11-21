<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Data;

use App\Models\IcoPurchase;
use App\Orchid\Layouts\AdminVipTable;
use App\Orchid\Support\AdminTable;
use Orchid\Screen\TD;

class UserFullDataIcoPurchasesLayout extends AdminVipTable
{
    public $target = 'ico_purchases';

    protected $title = 'ICO purchases';

    public function columns(): array
    {
        return [
            TD::make('purchase_id', __('On-chain ID'))
                ->render(fn (IcoPurchase $row) => AdminTable::id((int) $row->purchase_id)),
            TD::make('usdt_amount', __('USDT'))->align(TD::ALIGN_RIGHT)
                ->render(fn (IcoPurchase $row) => AdminTable::money($row->usdt_amount)),
            TD::make('race_amount', __('RACE'))->align(TD::ALIGN_RIGHT)
                ->render(fn (IcoPurchase $row) => number_format((float) $row->race_amount, 4)),
            TD::make('status', __('Status'))
                ->render(fn (IcoPurchase $row) => AdminTable::badge(
                    (string) $row->status,
                    $row->status === 'staked' ? 'success' : 'warning',
                )),
            TD::make('tx_hash', __('Tx'))
                ->render(fn (IcoPurchase $row) => AdminTable::mono($row->tx_hash)),
            TD::make('created_at', __('Date'))
                ->render(fn (IcoPurchase $row) => AdminTable::dateTime($row->created_at)),
        ];
    }
}
