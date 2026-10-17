<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToOwner, EncryptedForOwner, HasFactory;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['amount', 'to_amount', 'note'];

    protected $fillable = [
        'type', 'account_id', 'to_account_id', 'category_id', 'amount', 'currency',
        'to_amount', 'date', 'note', 'source_type', 'source_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => TransactionType::class,
            'amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'date' => 'date:Y-m-d',
        ];
    }

    /** Morph names of sources whose money is a loan, not income or spending. */
    public const DEBT_SOURCES = ['debt', 'debt_payment'];

    /** Excludes lending/repayments so they don't distort income and spending totals. */
    public function scopeCashflow(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('source_type')->orWhereNotIn('source_type', self::DEBT_SOURCES));
    }

    public function isDebtMovement(): bool
    {
        return in_array($this->source_type, self::DEBT_SOURCES, true);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
