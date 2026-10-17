<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Model;

class TrustedContact extends Model
{
    use BelongsToOwner, EncryptedForOwner;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['name', 'phone', 'relation'];

    protected $fillable = ['name', 'email', 'phone', 'relation'];
}
