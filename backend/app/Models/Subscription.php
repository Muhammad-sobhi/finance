<?php

namespace App\Models;

use App\Enums\Scope;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\Recurring;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use BelongsToOwner, HasFactory, Recurring;

    public const DUE_COLUMN = 'renew_date';

    protected $fillable = ['name', 'category_id', 'amount', 'currency', 'renew_date', 'start_date', 'scope', 'cycle', 'remind_days', 'auto_pay', 'account_id', 'notes', 'is_active'];

    protected function casts(): array
    {
        return $this->recurringCasts() + ['scope' => Scope::class, 'start_date' => 'date:Y-m-d'];
    }
}
