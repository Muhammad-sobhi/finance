<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use App\Models\Concerns\OwnedThroughParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class GameyaPayment extends Model
{
    use EncryptedForOwner, OwnedThroughParent;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['amount'];

    public const PARENT = [Gameya::class, 'gameya_id'];

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
