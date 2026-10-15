<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Services\DefaultCategories;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const THEMES = ['ledger', 'swiss', 'geo', 'vault'];

    public const LOCALES = ['ar', 'en'];

    protected $fillable = [
        'name', 'email', 'password', 'role', 'owner_id',
        'locale', 'theme', 'list_mode', 'base_currency', 'last_activity_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'role' => 'owner',
        'locale' => 'ar',
        'theme' => 'ledger',
        'list_mode' => 'table',
        'base_currency' => 'EGP',
    ];

    protected static function booted(): void
    {
        static::created(function (User $user) {
            if ($user->isOwner()) {
                DefaultCategories::seedFor($user);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'heir_access_activated_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    public function isHeir(): bool
    {
        return $this->role === UserRole::Heir;
    }

    /** The id whose data this user sees: themselves for owners, their owner for heirs. */
    public function ownerId(): int
    {
        return $this->isHeir() ? (int) $this->owner_id : (int) $this->id;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** The data owner (self for owners). */
    public function dataOwner(): User
    {
        return $this->isHeir() ? $this->owner : $this;
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }
}
