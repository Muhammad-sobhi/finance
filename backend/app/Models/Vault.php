<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Model;

/**
 * The owner's vault key, never in clear: wrapped by their PIN in the browser (`wrapped_key`,
 * optional) and/or kept as a copy for heirs (`escrow_key`, encrypted with the owner's data key).
 */
class Vault extends Model
{
    use BelongsToOwner, EncryptedForOwner;

    public const ENCRYPTED = ['escrow_key'];

    public const MIN_ITERATIONS = 310000;

    protected $fillable = ['salt', 'iterations', 'iv', 'wrapped_key', 'escrow_key'];

    protected $hidden = ['escrow_key'];

    protected function casts(): array
    {
        return ['iterations' => 'integer'];
    }

    public function hasPin(): bool
    {
        return $this->wrapped_key !== null;
    }

    public function hasHeirCopy(): bool
    {
        return $this->escrow_key !== null;
    }
}
