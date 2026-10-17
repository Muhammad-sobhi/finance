<?php

namespace App\Models;

use App\Enums\Cycle;
use App\Models\Concerns\BelongsToOwner;
use App\Models\Concerns\EncryptedForOwner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gameya extends Model
{
    use BelongsToOwner, EncryptedForOwner;

    /** Stored encrypted with the owner's data key (see EncryptedForOwner). */
    public const ENCRYPTED = ['name', 'organizer', 'amount', 'notes'];

    protected $fillable = ['name', 'organizer', 'role', 'members', 'my_turn', 'amount', 'currency', 'start_date', 'cycle', 'account_id', 'remind_days', 'notes'];

    protected $attributes = ['cycle' => 'monthly', 'role' => 'member'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'members' => 'integer', 'my_turn' => 'integer',
            'start_date' => 'date:Y-m-d', 'cycle' => Cycle::class, 'remind_days' => 'integer',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(GameyaPayment::class)->orderBy('round')->orderBy('kind');
    }

    /** The turns, when the owner manages this gameya. */
    public function slots(): HasMany
    {
        return $this->hasMany(GameyaMember::class)->orderBy('turn');
    }

    public function isManaged(): bool
    {
        return $this->role === 'manager';
    }

    /** Does the owner have a share (and so pays rounds and takes a pot) in this gameya? */
    public function hasMyTurn(): bool
    {
        return $this->my_turn !== null;
    }

    /** The latest round whose date has come (1 before the start). */
    public function currentRound(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $n = 1;
        while ($n < $this->members && $this->roundDate($n + 1)->lte($today)) {
            $n++;
        }

        return $n;
    }

    /** The pot one member takes each round. */
    public function payout(): float
    {
        return round((float) $this->amount * $this->members, 2);
    }

    public function roundDate(int $round): CarbonImmutable
    {
        return $this->cycle->nth(CarbonImmutable::parse($this->start_date), $round - 1);
    }

    public function paidRounds(): int
    {
        return $this->payments->where('kind', 'contribution')->count();
    }

    public function received(): bool
    {
        return $this->payments->where('kind', 'payout')->isNotEmpty();
    }

    /** My next round to pay; null when all are paid or I have no share. */
    public function nextRound(): ?int
    {
        $n = $this->paidRounds() + 1;

        return $this->hasMyTurn() && $n <= $this->members ? $n : null;
    }

    public function isDone(): bool
    {
        return $this->hasMyTurn()
            ? $this->nextRound() === null && $this->received()
            : $this->roundDate($this->members)->lt(CarbonImmutable::today()->startOfDay());
    }

    /** Paid in − taken out: positive = the group owes you; negative = you still owe the group. */
    public function position(): float
    {
        $paid = (float) $this->payments->where('kind', 'contribution')->sum('amount');
        $got = (float) $this->payments->where('kind', 'payout')->sum('amount');

        return round($paid - $got, 2);
    }
}
