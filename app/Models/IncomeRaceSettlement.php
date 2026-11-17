<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomeRaceSettlement extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'usdt_amount',
        'race_amount',
        'race_price_usd',
        'wallet_address',
        'status',
        'multisig_tx_id',
        'tx_hash',
        'transfer_calldata',
        'meta',
        'created_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
