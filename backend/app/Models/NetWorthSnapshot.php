<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Model;

class NetWorthSnapshot extends Model
{
    use BelongsToOwner, EncryptedForOwner;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['net_worth', 'breakdown'];

    protected $fillable = ['month', 'net_worth', 'breakdown'];

    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'net_worth' => 'decimal:2', 'breakdown' => 'array'];
    }
}
