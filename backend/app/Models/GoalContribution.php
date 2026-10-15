<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GoalContribution extends Model
{
    protected $fillable = ['amount', 'date', 'note'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'date' => 'date:Y-m-d'];
    }
}
