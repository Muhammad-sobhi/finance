<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Project;
use App\Models\ProjectPayment;
use Illuminate\Support\Facades\DB;

/** Each payment received for a project is an income transaction on the chosen account. */
class ProjectService
{
    public function __construct(private readonly TransactionService $tx) {}

    public function addPayment(Project $project, array $data): ProjectPayment
    {
        return DB::transaction(function () use ($project, $data) {
            $payment = $project->payments()->create($data);
            $account = Account::findOrFail($payment->account_id);
            $owner = $project->user;
            $categoryId = $project->category_id ?? $owner->categories()->where('key', 'freelance')->value('id');

            $this->tx->create($owner, [
                'type' => 'income',
                'account_id' => $account->id,
                'category_id' => $categoryId,
                'amount' => CurrencyConverter::for($owner)->convert((float) $payment->amount, $project->currency, $account->currency),
                'date' => $payment->date->format('Y-m-d'),
                'note' => trim("{$project->client} — {$project->name}".($payment->note ? " · {$payment->note}" : '')),
                'source_type' => $payment->getMorphClass(),
                'source_id' => $payment->id,
            ]);
            // Fully paid active projects close themselves.
            if ($project->status === 'active' && $project->fresh()->outstanding() <= 0) {
                $project->update(['status' => 'done']);
            }

            return $payment;
        });
    }

    public function deletePayment(ProjectPayment $payment): void
    {
        DB::transaction(function () use ($payment) {
            if ($payment->transaction) {
                $this->tx->delete($payment->transaction);
            }
            $payment->delete();
        });
    }

    /** Deleting a project removes its payments and their income transactions. */
    public function delete(Project $project): void
    {
        DB::transaction(function () use ($project) {
            $project->payments->each(fn (ProjectPayment $p) => $this->deletePayment($p));
            $project->delete();
        });
    }
}
