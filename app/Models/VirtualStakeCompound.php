<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualStakeCompound extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_MINTED = 'minted';

    protected $fillable = [
        'user_id',
        'stake_index',
        'usdt_amount',
        'race_amount',
        'race_price_usdt',
        'income_wallet_transaction_id',
        'status',
        'mint_tx_hash',
        'minted_at',
    ];

    protected function casts(): array
    {
        return [
            'minted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
