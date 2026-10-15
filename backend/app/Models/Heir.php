<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Heir extends Model
{
    use BelongsToOwner;

    protected $fillable = ['name', 'relation', 'phone', 'email', 'national_id', 'address', 'notes'];

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'address' => 'encrypted',
            'notes' => 'encrypted',
            'invited_at' => 'datetime',
        ];
    }

    public function login(): BelongsTo
    {
        return $this->belongsTo(User::class, 'login_user_id');
    }
}
