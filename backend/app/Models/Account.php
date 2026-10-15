<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\BelongsToOwner;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToOwner, HasFactory;

    protected $fillable = [
        'name', 'type', 'provider', 'currency', 'balance', 'credit_limit', 'due_day',
        'last4', 'account_number', 'notes', 'is_active', 'sort',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'due_day' => 'integer',
            'account_number' => 'encrypted',
            'notes' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
