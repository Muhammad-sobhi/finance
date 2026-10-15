<?php

namespace App\Http\Resources;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Category */
class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'type' => $this->type->value,
            'icon' => $this->icon,
            'color' => $this->color,
            'sort' => $this->sort,
            'usage_count' => (int) ($this->usage_count ?? 0),
        ];
    }
}
