<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use App\Models\Concerns\OwnedThroughParent;
use Illuminate\Database\Eloquent\Model;

class InvestmentValue extends Model
{
    use EncryptedForOwner, OwnedThroughParent;

    public const PARENT = [Investment::class, 'investment_id'];

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['value'];

    protected $fillable = ['date', 'value'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'value' => 'decimal:2'];
    }
}
