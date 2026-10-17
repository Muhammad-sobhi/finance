<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role->value,
            'read_only' => $this->isHeir(),
            'locale' => $this->locale,
            'theme' => $this->theme,
            'list_mode' => $this->list_mode,
            'phone_nav' => $this->phone_nav ?: User::PHONE_NAV_DEFAULT,
            'base_currency' => $this->dataOwner()->base_currency,
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'two_factor' => $this->two_factor_confirmed_at !== null,
            'is_admin' => $this->isOwner() && $this->is_admin,
        ];
    }
}
