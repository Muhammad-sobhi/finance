<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\Recurring;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use BelongsToOwner, HasFactory, Recurring;

    public const DUE_COLUMN = 'due_date';

    protected $fillable = ['name', 'category_id', 'amount', 'currency', 'due_date', 'cycle', 'remind_days', 'auto_pay', 'account_id', 'notes', 'is_active'];

    protected function casts(): array
    {
        return $this->recurringCasts();
    }
}
