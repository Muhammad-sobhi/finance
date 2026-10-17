<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use App\Models\Concerns\OwnedThroughParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's share for one round, as recorded by the manager (or claimed by the member). */
class GameyaMemberPayment extends Model
{
    use EncryptedForOwner, OwnedThroughParent;

    /** Stored encrypted with the manager's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['amount'];

    public const PARENT = [Gameya::class, 'gameya_id'];

    protected $fillable = ['gameya_id', 'round', 'amount', 'date', 'confirmed'];

    protected function casts(): array
    {
        return ['round' => 'integer', 'amount' => 'decimal:2', 'date' => 'date:Y-m-d', 'confirmed' => 'boolean'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(GameyaMember::class, 'member_id');
    }
}
