<?php

namespace App\Models;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Debt extends Model
{
    use BelongsToOwner, HasFactory;

    protected $fillable = ['person', 'phone', 'direction', 'amount', 'currency', 'date', 'due_date', 'notes', 'status', 'account_id', 'remind_days'];

    protected function casts(): array
    {
        return [
            'direction' => DebtDirection::class,
            'status' => DebtStatus::class,
            'amount' => 'decimal:2',
            'date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'notes' => 'encrypted',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class)->orderByDesc('date')->orderByDesc('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** The transaction recording the original loan, if it moved money. */
    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'source');
    }

    public function paid(): float
    {
        // withSum() leaves NULL when there are no payments; only query when the sum wasn't loaded at all.
        $sum = array_key_exists('payments_sum_amount', $this->attributes) ? $this->payments_sum_amount : $this->payments()->sum('amount');

        return round((float) $sum, 2);
    }

    public function remaining(): float
    {
        return max(0, round((float) $this->amount - $this->paid(), 2));
    }
}
