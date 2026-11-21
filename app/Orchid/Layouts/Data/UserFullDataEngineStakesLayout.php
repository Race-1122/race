<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Data;

use App\Models\BlockchainEngineStake;
use App\Orchid\Layouts\AdminVipTable;
use App\Orchid\Support\AdminTable;
use Orchid\Screen\TD;

class UserFullDataEngineStakesLayout extends AdminVipTable
{
    public $target = 'engine_stakes';

    protected $title = 'On-chain Engine stakes';

    public function columns(): array
    {
        return [
            TD::make('stake_index', __('Index'))
                ->render(fn (BlockchainEngineStake $row) => AdminTable::id((int) $row->stake_index)),
            TD::make('source_type', __('Source'))
                ->render(fn (BlockchainEngineStake $row) => AdminTable::badge((string) ($row->source_type ?: '—'), 'vip')),
            TD::make('principal_usdt', __('USDT'))->align(TD::ALIGN_RIGHT)
                ->render(fn (BlockchainEngineStake $row) => AdminTable::money($row->principal_usdt)),
            TD::make('staked_race', __('RACE'))->align(TD::ALIGN_RIGHT)
                ->render(fn (BlockchainEngineStake $row) => number_format((float) $row->staked_race, 4)),
            TD::make('status', __('Status'))
                ->render(fn (BlockchainEngineStake $row) => AdminTable::badge(
                    $row->withdrawn ? 'withdrawn' : (string) ($row->status ?: 'active'),
                    $row->withdrawn ? 'muted' : 'success',
                )),
            TD::make('tx_hash', __('Tx'))
                ->render(fn (BlockchainEngineStake $row) => AdminTable::mono($row->tx_hash)),
            TD::make('started_at', __('Started'))
                ->render(function (BlockchainEngineStake $row) {
                    $ts = (int) $row->started_at;
                    if ($ts <= 0) {
                        return AdminTable::text('—');
                    }

                    return AdminTable::dateTime(now()->setTimestamp($ts));
                }),
        ];
    }
}
