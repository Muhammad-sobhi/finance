<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    use BelongsToOwner;

    protected $fillable = ['name', 'target_amount', 'currency', 'target_date', 'account_id', 'notes'];

    protected function casts(): array
    {
        return ['target_amount' => 'decimal:2', 'target_date' => 'date:Y-m-d'];
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(GoalContribution::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
