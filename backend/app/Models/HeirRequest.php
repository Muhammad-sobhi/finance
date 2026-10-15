<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HeirRequest extends Model
{
    use BelongsToOwner;

    public const OPEN = ['pending', 'confirmed'];

    protected $fillable = ['heir_id', 'status', 'requested_ip', 'confirmed_by', 'confirmed_at', 'activates_at', 'cancelled_at'];

    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime', 'activates_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function heir(): BelongsTo
    {
        return $this->belongsTo(Heir::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(TrustedContact::class, 'confirmed_by');
    }
}
