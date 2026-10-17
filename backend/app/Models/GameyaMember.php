<?php

namespace App\Models;

use App\Models\Concerns\EncryptedForOwner;
use App\Models\Concerns\OwnedThroughParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One turn in a gameya the owner manages, and the person holding it. */
class GameyaMember extends Model
{
    use EncryptedForOwner, OwnedThroughParent;

    /** Stored encrypted with the manager's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['name', 'phone', 'email', 'notes', 'payout_amount'];

    public const PARENT = [Gameya::class, 'gameya_id'];

    protected $fillable = ['turn', 'name', 'phone', 'email', 'notes', 'is_me'];

    protected function casts(): array
    {
        return [
            'turn' => 'integer', 'is_me' => 'boolean', 'invited_at' => 'datetime', 'accepted_at' => 'datetime',
            'payout_date' => 'date:Y-m-d', 'payout_amount' => 'decimal:2',
        ];
    }

    public function gameya(): BelongsTo
    {
        return $this->belongsTo(Gameya::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(GameyaMemberPayment::class, 'member_id')->orderBy('round');
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    public function isLinked(): bool
    {
        return $this->linked_user_id !== null && $this->accepted_at !== null;
    }

    /** none | invited | linked — "invited" never reveals whether the email has an account. */
    public function linkStatus(): string
    {
        return $this->isLinked() ? 'linked' : ($this->invited_at ? 'invited' : 'none');
    }

    /** Rounds this member has paid (confirmed by the manager, or only claimed). */
    public function paidRounds(bool $confirmedOnly = false): array
    {
        return $this->payments->filter(fn ($p) => ! $confirmedOnly || $p->confirmed)->pluck('round')->all();
    }
}
