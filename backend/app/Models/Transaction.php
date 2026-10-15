<?php

namespace App\Models;

use App\Enums\TransactionType;
use App\Models\Concerns\BelongsToOwner;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToOwner, HasFactory;

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
