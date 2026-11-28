<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RaceTransferMakeWhole extends Model
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'tx_hash',
        'from_wallet',
        'to_wallet',
        'fee_race',
        'received_race',
        'fee_wei',
        'received_wei',
        'mint_tx_hash',
        'status',
        'skip_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
};
