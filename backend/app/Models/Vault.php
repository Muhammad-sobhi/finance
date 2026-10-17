<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

/** The owner's wrapped vault key (see the vault migration). Opaque to the server. */
class Vault extends Model
{
    use BelongsToOwner;

    public const MIN_ITERATIONS = 310000;

    protected $fillable = ['salt', 'iterations', 'iv', 'wrapped_key'];

    protected function casts(): array
    {
        return ['iterations' => 'integer'];
    }
}
