<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VirtualStakeCompound extends Model
{
    protected $fillable = [
        'user_id',
        'stake_index',
        'usdt_amount',
        'race_amount',
        'race_price_usdt',
        'income_wallet_transaction_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
