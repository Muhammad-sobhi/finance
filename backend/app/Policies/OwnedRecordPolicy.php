<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared rules for every owner-scoped model: owners and their heirs may read,
 * only the owner may write. Heirs are additionally blocked by middleware.
 */
abstract class OwnedRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $model): bool
    {
        return (int) $model->getAttribute('user_id') === $user->ownerId();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->isOwner() && (int) $model->getAttribute('user_id') === $user->id;
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->update($user, $model);
    }
}
