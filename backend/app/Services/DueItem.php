<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Something with a due date that may need an alert. Name and amount are owner data:
 * when an alert waits in the queue they are stored encrypted (see __serialize).
 */
final class DueItem
{
    public function __construct(
        public readonly int $ownerId,
        public readonly string $kind,      // bill | subscription | owed_to_me | i_owe | credit_card | maturity
        public readonly int $id,
        public readonly string $name,
        public readonly CarbonImmutable $due,
        public readonly float $amount,
        public readonly string $currency,
        public readonly ?int $remindDays,  // null → owner default
        public readonly string $path,      // SPA route to open
    ) {}

    public function __serialize(): array
    {
        $data = get_object_vars($this);
        $data['due'] = $this->due->toDateString();
        $data['sealed'] = app(DataVault::class)->encrypt($this->ownerId, json_encode([$this->name, $this->amount]));
        unset($data['name'], $data['amount']);

        return $data;
    }

    public function __unserialize(array $data): void
    {
        [$name, $amount] = json_decode(app(DataVault::class)->decrypt($data['ownerId'], $data['sealed']), true);
        $this->ownerId = $data['ownerId'];
        $this->kind = $data['kind'];
        $this->id = $data['id'];
        $this->name = $name;
        $this->due = CarbonImmutable::parse($data['due']);
        $this->amount = (float) $amount;
        $this->currency = $data['currency'];
        $this->remindDays = $data['remindDays'];
        $this->path = $data['path'];
    }

    /** before | due | overdue | null (nothing to say today). */
    public function stage(CarbonImmutable $today, int $defaultDays): ?string
    {
        $days = $this->days($today);
        $remind = $this->remindDays ?? $defaultDays;

        return match (true) {
            $days < 0 => 'overdue',
            $days === 0 => 'due',
            $days <= $remind => 'before',
            default => null,
        };
    }

    public function days(CarbonImmutable $today): int
    {
        return (int) $today->diffInDays($this->due, false);
    }
}
