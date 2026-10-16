<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CategoryType;
use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\BackupService;
use App\Services\BudgetService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Getting data out (CSV, encrypted backup) and in (bank statement import). */
class DataController extends Controller
{
    /** Transactions as CSV (UTF-8 with BOM so Excel shows Arabic correctly). */
    public function exportTransactions(Request $request): StreamedResponse
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $user = $request->user();
        $locale = $user->locale;
        $query = Transaction::ownedBy($user)->with(['account:id,name', 'toAccount:id,name', 'category:id,key,name'])
            ->when($request->filled('from'), fn ($q) => $q->where('date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('date', '<=', $request->input('to')))
            ->orderBy('date')->orderBy('id');
        $name = 'transactions-'.($request->input('from') ?? 'all').'-'.($request->input('to') ?? now()->toDateString()).'.csv';

        return response()->streamDownload(function () use ($query, $locale) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map(fn ($c) => __("export.{$c}", [], $locale), ['date', 'type', 'amount', 'currency', 'account', 'to_account', 'to_amount', 'category', 'note']));
            $query->chunk(500, function ($rows) use ($out, $locale) {
                foreach ($rows as $t) {
                    fputcsv($out, [
                        $t->date->format('Y-m-d'), __("export.types.{$t->type->value}", [], $locale), $t->amount, $t->currency,
                        $t->account?->name, $t->toAccount?->name, $t->to_amount,
                        $t->category ? ($t->category->key ? __("notices.categories.{$t->category->key}", [], $locale) : $t->category->name) : '',
                        $this->safeCell($t->note),
                    ]);
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** The owner's whole ledger, sealed with their passphrase. Needs the account password too. */
    public function backup(Request $request, BackupService $backups)
    {
        $user = $request->user();
        abort_unless($user->isOwner(), 403);
        $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'passphrase' => ['required', 'string', 'min:10', 'max:200'],
        ]);

        return response()->streamDownload(fn () => print ($backups->seal($user, $request->input('passphrase'))),
            'dinar-backup-'.now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json']);
    }

    /**
     * Import parsed bank-statement rows into one account. The browser parses the CSV and maps
     * columns; this validates, skips rows already recorded, guesses categories from history,
     * and records the rest through the ledger. `dry_run` only counts.
     */
    public function import(Request $request, TransactionService $service, BudgetService $budgets): JsonResponse
    {
        Gate::authorize('create', Transaction::class);
        $user = $request->user();
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)],
            'dry_run' => ['sometimes', 'boolean'],
            'rows' => ['required', 'array', 'min:1', 'max:1000'],
            'rows.*.date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rows.*.amount' => ['required', 'numeric', 'not_in:0', 'between:-9999999999999,9999999999999'],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
            'rows.*.category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('user_id', $user->id)],
        ]);
        $accountId = (int) $data['account_id'];
        $rows = collect($data['rows'])->map(fn ($r) => [
            'type' => (float) $r['amount'] < 0 ? TransactionType::Expense : TransactionType::Income,
            'amount' => round(abs((float) $r['amount']), 2), 'date' => $r['date'],
            'note' => trim((string) ($r['note'] ?? '')) ?: null, 'category_id' => $r['category_id'] ?? null,
        ]);

        // Duplicates: rows already in this account with the same date, direction, amount and note.
        // Counted as a multiset, so two identical coffees stay two.
        $sig = fn ($type, $date, $amount, $note) => implode('|', [$type, $date, number_format((float) $amount, 2, '.', ''), mb_strtolower(trim((string) $note))]);
        $existing = Transaction::where('account_id', $accountId)->whereIn('date', $rows->pluck('date')->unique()->all())
            ->get(['type', 'date', 'amount', 'note'])
            ->countBy(fn ($t) => $sig($t->type->value, $t->date->format('Y-m-d'), $t->amount, $t->note))->all();
        $fresh = $rows->filter(function ($r) use (&$existing, $sig) {
            $k = $sig($r['type']->value, $r['date'], $r['amount'], $r['note']);
            if (($existing[$k] ?? 0) > 0) {
                $existing[$k]--;

                return false;
            }

            return true;
        })->values();

        // Category: as given, else what the same description was filed under before.
        $cats = Category::where('user_id', $user->id)->get(['id', 'type'])->keyBy('id');
        $learned = Transaction::ownedBy($user)->whereNotNull('category_id')->whereNotNull('note')
            ->whereIn(DB::raw('lower(note)'), $fresh->pluck('note')->filter()->map(fn ($n) => mb_strtolower($n))->unique()->values()->all())
            ->orderBy('id')->get(['note', 'category_id', 'type'])
            ->mapWithKeys(fn ($t) => [$t->type->value.'|'.mb_strtolower($t->note) => $t->category_id])->all();
        $fresh = $fresh->map(function ($r) use ($cats, $learned) {
            $given = $r['category_id'] ? $cats->get($r['category_id']) : null;
            $wanted = $r['type'] === TransactionType::Expense ? CategoryType::Expense : CategoryType::Income;
            $r['category_id'] = $given && $given->type === $wanted ? $given->id : ($learned[$r['type']->value.'|'.mb_strtolower((string) $r['note'])] ?? null);

            return $r;
        });

        $summary = ['rows' => $rows->count(), 'new' => $fresh->count(), 'duplicates' => $rows->count() - $fresh->count(),
            'categorized' => $fresh->whereNotNull('category_id')->count()];
        if (! ($data['dry_run'] ?? false) && $fresh->isNotEmpty()) {
            DB::transaction(function () use ($fresh, $service, $user, $accountId) {
                foreach ($fresh as $r) {
                    $service->create($user, ['type' => $r['type']->value, 'account_id' => $accountId, 'amount' => $r['amount'],
                        'date' => $r['date'], 'note' => $r['note'], 'category_id' => $r['category_id']]);
                }
            });
            $budgets->watch($user);
            $summary['imported'] = $fresh->count();
        }

        return response()->json($summary);
    }

    /** Stop spreadsheet formula injection from notes typed in the app or imported. */
    private function safeCell(?string $v): ?string
    {
        return $v !== null && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
