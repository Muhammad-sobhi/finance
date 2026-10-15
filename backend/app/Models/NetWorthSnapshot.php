<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class NetWorthSnapshot extends Model
{
    use BelongsToOwner;

    protected $fillable = ['month', 'net_worth', 'breakdown'];

    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'net_worth' => 'decimal:2', 'breakdown' => 'array'];
    }
}
