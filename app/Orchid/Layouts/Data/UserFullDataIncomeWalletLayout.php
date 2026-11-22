<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Data;

use App\Models\IncomeWalletTransaction;
use App\Orchid\Layouts\AdminVipTable;
use App\Orchid\Support\AdminTable;
use Orchid\Screen\TD;

class UserFullDataIncomeWalletLayout extends AdminVipTable
{
    public $target = 'income_wallet_rows';

    protected $title = 'Virtual income wallet (latest 50)';

    public function columns(): array
    {
        return [
            TD::make('id', __('ID'))->width('72px')
                ->render(fn (IncomeWalletTransaction $row) => AdminTable::id($row->id)),
            TD::make('type', __('Type'))
                ->render(fn (IncomeWalletTransaction $row) => AdminTable::badge((string) $row->type, 'vip')),
            TD::make('direction', __('Dir'))
                ->render(fn (IncomeWalletTransaction $row) => AdminTable::badge((string) $row->direction, $row->direction === 'credit' ? 'success' : 'danger')),
            TD::make('balance_before_usd', __('Before'))->align(TD::ALIGN_RIGHT)
                ->render(fn (IncomeWalletTransaction $row) => AdminTable::money($row->balance_before_usd ?? 0)),
            TD::make('amount', __('Amount'))->align(TD::ALIGN_RIGHT)
                ->render(fn (IncomeWalletTransaction $row) => AdminTable::money($row->amount)),
            TD::make('balance_after_usd', __('After'))->align(TD::ALIGN_RIGHT)
                ->render(fn (IncomeWalletTransaction $row) => AdminTable::money($row->balance_after_usd ?? 0)),
            TD::make('status', __('Status'))
                ->render(fn (IncomeWalletTransaction $row) => AdminTable::text($row->status)),
            TD::make('created_at', __('Date'))
                ->render(fn (IncomeWalletTransaction $row) => AdminTable::dateTime($row->created_at)),
        ];
    }
}
