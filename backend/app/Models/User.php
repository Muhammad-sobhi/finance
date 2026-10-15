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
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasPushSubscriptions, Notifiable;

    public const THEMES = ['ledger', 'swiss', 'geo', 'vault'];

    public const LOCALES = ['ar', 'en'];

    protected $fillable = [
        'name', 'email', 'password', 'role', 'owner_id',
        'locale', 'theme', 'list_mode', 'base_currency', 'last_activity_at',
        'gold_price_24k', 'silver_price', 'alert_days', 'email_alerts', 'auto_fetch_rates', 'auto_metal_prices',
        'heir_enabled', 'heir_inactivity_days', 'heir_grace_days',
        'zakat_nisab', 'zakat_calendar', 'zakat_date', 'zakat_deduct_debts',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $attributes = [
        'role' => 'owner',
        'locale' => 'ar',
        'theme' => 'ledger',
        'list_mode' => 'table',
        'base_currency' => 'EGP',
        'gold_price_24k' => 0,
        'silver_price' => 0,
        'alert_days' => 3,
        'email_alerts' => true,
        'auto_fetch_rates' => false,
        'auto_metal_prices' => false,
        'heir_enabled' => false,
        'heir_inactivity_days' => 60,
        'heir_grace_days' => 14,
        'zakat_nisab' => 'gold',
        'zakat_calendar' => 'hijri',
        'zakat_deduct_debts' => true,
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
            'gold_price_24k' => 'decimal:2',
            'silver_price' => 'decimal:2',
            'alert_days' => 'integer',
            'email_alerts' => 'boolean',
            'auto_fetch_rates' => 'boolean',
            'auto_metal_prices' => 'boolean',
            'metal_prices_updated_at' => 'datetime',
            'heir_enabled' => 'boolean',
            'heir_inactivity_days' => 'integer',
            'heir_grace_days' => 'integer',
            'heir_warning_started_at' => 'datetime',
            'heir_last_reminder_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'zakat_date' => 'date:Y-m-d',
            'zakat_deduct_debts' => 'boolean',
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

    public function debts(): HasMany
    {
        return $this->hasMany(Debt::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function incomeSources(): HasMany
    {
        return $this->hasMany(IncomeSource::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function investments(): HasMany
    {
        return $this->hasMany(Investment::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function heirs(): HasMany
    {
        return $this->hasMany(Heir::class);
    }

    public function trustedContacts(): HasMany
    {
        return $this->hasMany(TrustedContact::class);
    }

    public function heirRequests(): HasMany
    {
        return $this->hasMany(HeirRequest::class);
    }

    public function heirAccessActive(): bool
    {
        return $this->heir_access_activated_at !== null;
    }

    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class);
    }

    public function installmentPlans(): HasMany
    {
        return $this->hasMany(InstallmentPlan::class);
    }

    public function gameyas(): HasMany
    {
        return $this->hasMany(Gameya::class);
    }

    public function zakatPayments(): HasMany
    {
        return $this->hasMany(ZakatPayment::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(NetWorthSnapshot::class);
    }

    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }
}
