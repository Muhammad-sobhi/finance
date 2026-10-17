<?php

namespace App\Models;

use App\Enums\Cycle;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentPlan extends Model
{
    use BelongsToOwner, EncryptedForOwner;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['name', 'provider', 'installment_amount', 'down_payment', 'notes'];

    protected $fillable = ['name', 'provider', 'currency', 'installment_amount', 'count', 'first_due_date', 'cycle', 'down_payment', 'account_id', 'category_id', 'remind_days', 'notes'];

    protected $attributes = ['cycle' => 'monthly'];

    protected function casts(): array
    {
        return [
            'installment_amount' => 'decimal:2', 'down_payment' => 'decimal:2', 'count' => 'integer',
            'first_due_date' => 'date:Y-m-d', 'cycle' => Cycle::class, 'remind_days' => 'integer',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InstallmentPayment::class)->orderBy('number');
    }

    public function paidCount(): int
    {
        return (int) ($this->payments_count ?? $this->payments()->count());
    }

    public function isDone(): bool
    {
        return $this->paidCount() >= $this->count;
    }

    /** Due date of installment number `$n` (1-based). */
    public function dueOf(int $n): CarbonImmutable
    {
        return $this->cycle->nth(CarbonImmutable::parse($this->first_due_date), $n - 1);
    }

    public function nextDue(): ?CarbonImmutable
    {
        return $this->isDone() ? null : $this->dueOf($this->paidCount() + 1);
    }

    /** Still to pay, assuming the remaining installments at the plan amount. */
    public function remaining(): float
    {
        return round(max(0, $this->count - $this->paidCount()) * (float) $this->installment_amount, 2);
    }

    /** Installments falling due up to `$until` that are still unpaid. */
    public function remainingUntil(CarbonImmutable $until): float
    {
        $sum = 0.0;
        for ($n = $this->paidCount() + 1; $n <= $this->count && $this->dueOf($n)->lte($until); $n++) {
            $sum += (float) $this->installment_amount;
        }

        return round($sum, 2);
    }
}
