<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StakeMintAdvance extends Model
{
    protected $fillable = [
        'user_id',
        'stake_index',
        'days_outstanding',
        'usdt_outstanding',
        'recoup_pending_usdt',
        'last_onchain_reward_at',
        'race_minted_total',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
