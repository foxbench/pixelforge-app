<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public const PURCHASE = 'purchase';

    public const HOLD = 'hold';

    public const RELEASE = 'release';

    public const CHARGE = 'charge';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_pico' => 'integer'];
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
