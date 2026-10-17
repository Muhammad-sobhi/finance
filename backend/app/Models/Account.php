<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToOwner, EncryptedForOwner, HasFactory;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['name', 'provider', 'balance', 'credit_limit', 'last4', 'account_number', 'notes'];

    protected $fillable = [
        'name', 'type', 'provider', 'currency', 'balance', 'credit_limit', 'due_day',
        'last4', 'account_number', 'notes', 'is_active', 'sort',
    ];

    protected $attributes = ['balance' => 0];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'due_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
