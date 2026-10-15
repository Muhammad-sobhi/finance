<?php

namespace App\Models;

use App\Enums\CategoryType;
use App\Models\Concerns\BelongsToOwner;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use BelongsToOwner, HasFactory;

    protected $fillable = ['key', 'name', 'type', 'icon', 'color', 'sort'];

    protected function casts(): array
    {
        return ['type' => CategoryType::class];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
