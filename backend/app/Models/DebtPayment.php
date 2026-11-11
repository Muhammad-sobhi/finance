<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use App\Models\Concerns\OwnedThroughParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class DebtPayment extends Model
{
    use EncryptedForOwner, OwnedThroughParent;

    public const PARENT = [Debt::class, 'debt_id'];

    /** Money paid back. */
    public const PAYMENT = 'payment';

    /** More money lent/borrowed on top of the debt's original amount. */
    public const ADVANCE = 'advance';

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['amount', 'note'];

    protected $fillable = ['kind', 'amount', 'date', 'account_id', 'note'];

    protected $attributes = ['kind' => self::PAYMENT];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'date' => 'date:Y-m-d'];
    }

    public function isAdvance(): bool
    {
        return $this->kind === self::ADVANCE;
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
