<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToOwner;

    public const STATUSES = ['active', 'done', 'cancelled'];

    protected $fillable = ['client', 'name', 'amount', 'currency', 'start_date', 'due_date', 'status', 'category_id', 'remind_days', 'notes'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'start_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'notes' => 'encrypted'];
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
        return round((float) ($this->payments_sum_amount ?? $this->payments()->sum('amount')), 2);
    }

    public function outstanding(): float
    {
        return max(0, round((float) $this->amount - $this->received(), 2));
    }
}
