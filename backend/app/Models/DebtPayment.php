<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class DebtPayment extends Model
{
    use EncryptedForOwner;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['amount', 'note'];

    protected $fillable = ['amount', 'date', 'account_id', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'date' => 'date:Y-m-d'];
    }

    /** Rows carry the owner's id (from the debt) so they can be decrypted on their own. */
    protected static function booted(): void
    {
        static::creating(fn (DebtPayment $p) => $p->user_id ??= Debt::whereKey($p->debt_id)->value('user_id'));
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function transaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'source');
    }
}
