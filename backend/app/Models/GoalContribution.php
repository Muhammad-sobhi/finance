<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use App\Models\Concerns\OwnedThroughParent;
use Illuminate\Database\Eloquent\Model;

class GoalContribution extends Model
{
    use EncryptedForOwner, OwnedThroughParent;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['amount', 'note'];

    public const PARENT = [Goal::class, 'goal_id'];

    protected $fillable = ['amount', 'date', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'date' => 'date:Y-m-d'];
    }
}
