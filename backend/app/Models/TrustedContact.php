<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOwner;
use Illuminate\Database\Eloquent\Model;

class TrustedContact extends Model
{
    use BelongsToOwner;

    protected $fillable = ['name', 'email', 'phone', 'relation'];
}
