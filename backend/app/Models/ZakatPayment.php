<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ZakatPayment extends Model
{
    use BelongsToOwner, EncryptedForOwner;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['amount', 'zakatable_wealth', 'note'];

    protected $fillable = ['amount', 'date', 'zakatable_wealth', 'account_id', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'zakatable_wealth' => 'decimal:2', 'date' => 'date:Y-m-d'];
    }

    public function transaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'source');
    }
}
