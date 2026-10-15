<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every financial record belongs to an owner (`user_id`). Heirs read the owner's data.
 */
trait BelongsToOwner
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where($this->getTable().'.user_id', $user->ownerId());
    }
}
