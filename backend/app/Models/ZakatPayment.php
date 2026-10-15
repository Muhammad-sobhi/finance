<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class ZakatPayment extends Model
{
    use BelongsToOwner;

    protected $fillable = ['amount', 'date', 'zakatable_wealth', 'account_id', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'zakatable_wealth' => 'decimal:2', 'date' => 'date:Y-m-d'];
    }

    public function transaction(): MorphOne
    {
        return $this->morphOne(Transaction::class, 'source');
    }
}
