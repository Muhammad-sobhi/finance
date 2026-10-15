<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvestmentValue extends Model
{
    protected $fillable = ['date', 'value'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'value' => 'decimal:2'];
    }
}
