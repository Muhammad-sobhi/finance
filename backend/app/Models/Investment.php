<?php

namespace App\Models;

use App\Enums\InvestmentType;
use App\Enums\PayoutCycle;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Investment extends Model
{
    use BelongsToOwner, EncryptedForOwner, HasFactory;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['name', 'platform', 'invested_amount', 'current_value', 'quantity', 'annual_return', 'notes'];

    protected $fillable = [
        'name', 'type', 'platform', 'invested_amount', 'current_value', 'currency', 'quantity', 'unit',
        'start_date', 'maturity_date', 'annual_return', 'payout_cycle', 'remind_days', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => InvestmentType::class,
            'payout_cycle' => PayoutCycle::class,
            'invested_amount' => 'decimal:2',
            'current_value' => 'decimal:2',
            'quantity' => 'decimal:6',
            'annual_return' => 'decimal:3',
            'start_date' => 'date:Y-m-d',
            'maturity_date' => 'date:Y-m-d',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(InvestmentValue::class)->orderBy('date');
    }

    /** Keep one snapshot per day so growth charts follow every value change. */
    protected static function booted(): void
    {
        static::saved(function (Investment $inv) {
            if ($inv->wasRecentlyCreated || $inv->wasChanged('current_value')) {
                $inv->values()->updateOrCreate(['date' => now()->toDateString()], ['value' => $inv->current_value]);
            }
        });
    }

    public function profit(): float
    {
        return round((float) $this->current_value - (float) $this->invested_amount, 2);
    }

    public function roi(): ?float
    {
        return (float) $this->invested_amount > 0 ? round($this->profit() / (float) $this->invested_amount * 100, 2) : null;
    }
}
