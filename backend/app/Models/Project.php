<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToOwner, EncryptedForOwner;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['client', 'name', 'amount', 'notes'];

    public const STATUSES = ['active', 'done', 'cancelled'];

    protected $fillable = ['client', 'name', 'amount', 'currency', 'start_date', 'due_date', 'status', 'category_id', 'remind_days', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'start_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d'];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ProjectPayment::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function received(): float
    {
        return round($this->payments->sum(fn (ProjectPayment $p) => (float) $p->amount), 2);
    }

    public function outstanding(): float
    {
        return max(0, round((float) $this->amount - $this->received(), 2));
    }
}
