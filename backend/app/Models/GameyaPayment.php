<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class GameyaPayment extends Model
{
    protected $fillable = ['kind', 'round', 'amount', 'date', 'account_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'date' => 'date:Y-m-d', 'round' => 'integer'];
    }

    public function gameya(): BelongsTo
    {
        return $this->belongsTo(Gameya::class);
    }

    public function transaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'source');
    }
}
