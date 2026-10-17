<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

/** One encrypted vault entry. Only `kind` and the optional reminder date are readable here. */
class VaultItem extends Model
{
    use BelongsToOwner;

    public const KINDS = ['password', 'bank', 'card', 'document', 'insurance', 'crypto', 'note'];

    protected $fillable = ['kind', 'iv', 'ciphertext', 'remind_on'];

    protected function casts(): array
    {
        return ['remind_on' => 'date:Y-m-d'];
    }
}
