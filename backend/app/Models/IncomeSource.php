<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use App\Models\Concerns\Recurring;
use Illuminate\Database\Eloquent\Model;

class IncomeSource extends Model
{
    use BelongsToOwner, EncryptedForOwner, Recurring;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['name', 'amount', 'notes'];

    public const DUE_COLUMN = 'next_date';

    public const TX_TYPE = 'income';

    protected $fillable = ['name', 'category_id', 'amount', 'currency', 'next_date', 'cycle', 'remind_days', 'auto_pay', 'account_id', 'notes', 'is_active'];

    protected function casts(): array
    {
        return $this->recurringCasts();
    }
}
