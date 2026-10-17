<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use App\Models\Concerns\OwnedThroughParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ProjectPayment extends Model
{
    use EncryptedForOwner, OwnedThroughParent;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['amount', 'note'];

    public const PARENT = [Project::class, 'project_id'];

    protected $fillable = ['amount', 'date', 'account_id', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'date' => 'date:Y-m-d'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function transaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'source');
    }
}
