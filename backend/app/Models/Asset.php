<?php

namespace App\Models;

use App\Enums\AssetType;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    use BelongsToOwner, EncryptedForOwner, HasFactory;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['name', 'purchase_price', 'current_value', 'grams', 'location', 'notes'];

    protected $fillable = ['name', 'type', 'currency', 'purchase_price', 'purchase_date', 'current_value', 'grams', 'karat', 'location', 'notes', 'zakatable'];

    protected function casts(): array
    {
        return [
            'zakatable' => 'boolean',
            'type' => AssetType::class,
            'purchase_price' => 'decimal:2',
            'purchase_date' => 'date:Y-m-d',
            'current_value' => 'decimal:2',
            'grams' => 'decimal:3',
            'karat' => 'integer',
        ];
    }

    /** Zakat counts money-like holdings by default; a home, land, car or device only when marked (e.g. held for trade). */
    public function isZakatable(): bool
    {
        return $this->zakatable ?? in_array($this->type, [AssetType::Gold, AssetType::Silver, AssetType::Jewelry, AssetType::ForeignCash], true);
    }

    /**
     * Gold/silver: price/gram from the owner's settings (base currency) × grams × karat/24.
     * Returns [value, currency].
     *
     * @return array{0: float, 1: string}
     */
    public function valuation(User $owner): array
    {
        if ($this->type->isMetal()) {
            $grams = (float) $this->grams;
            $value = $this->type->value === 'gold'
                ? $grams * (float) $owner->gold_price_24k * (($this->karat ?: 24) / 24)
                : $grams * (float) $owner->silver_price;

            return [round($value, 2), $owner->base_currency];
        }

        return [(float) ($this->current_value ?? $this->purchase_price ?? 0), $this->currency];
    }
}
