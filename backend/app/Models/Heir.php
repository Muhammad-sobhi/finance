<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Heir extends Model
{
    use BelongsToOwner, EncryptedForOwner;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['name', 'relation', 'phone', 'national_id', 'address', 'notes'];

    protected $fillable = ['name', 'relation', 'phone', 'email', 'national_id', 'address', 'notes'];

    protected function casts(): array
    {
        return [
            'invited_at' => 'datetime',
        ];
    }

    public function login(): BelongsTo
    {
        return $this->belongsTo(User::class, 'login_user_id');
    }
}
