<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use BelongsToOwner;

    protected $fillable = ['currency', 'rate', 'auto_update'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:6', 'auto_update' => 'boolean'];
    }
}
