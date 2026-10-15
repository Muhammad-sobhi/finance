<?php

namespace App\Models\Concerns;

use App\Enums\Cycle;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Shared behaviour of bills and subscriptions: a due date that rolls forward by
 * its cycle each time it's paid. The model names its due-date column in DUE_COLUMN.
 */
trait Recurring
{
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function dueDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->getAttribute(static::DUE_COLUMN));
    }

    /** Move to the next cycle; one-off items become inactive. */
    public function roll(): void
    {
        $next = $this->cycle->next($this->dueDate());
        if ($next === null) {
            $this->is_active = false;
        } else {
            $this->setAttribute(static::DUE_COLUMN, $next->toDateString());
        }
    }

    public function monthlyCost(): float
    {
        return (float) $this->amount * $this->cycle->monthlyFactor();
    }

    protected function recurringCasts(): array
    {
        return [
            'cycle' => Cycle::class,
            'amount' => 'decimal:2',
            'auto_pay' => 'boolean',
            'is_active' => 'boolean',
            'last_paid_at' => 'date:Y-m-d',
            static::DUE_COLUMN => 'date:Y-m-d',
        ];
    }
}
