<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Model;

class InvestmentValue extends Model
{
    use EncryptedForOwner;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['value'];

    protected $fillable = ['date', 'value'];

    /** Rows carry the owner's id (from the investment) so they can be decrypted on their own. */
    protected static function booted(): void
    {
        static::creating(fn (InvestmentValue $v) => $v->user_id ??= Investment::whereKey($v->investment_id)->value('user_id'));
    }

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'value' => 'decimal:2'];
    }
}
