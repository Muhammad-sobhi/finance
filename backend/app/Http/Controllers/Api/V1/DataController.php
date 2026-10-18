<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Getting data out: CSV export and encrypted backup. */
class DataController extends Controller
{
    /** Transactions as CSV (UTF-8 with BOM so Excel shows Arabic correctly). */
    public function exportTransactions(Request $request): StreamedResponse
    {
        $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $user = $request->user();
        $locale = $user->locale;
        $query = Transaction::ownedBy($user)->with(['account:id,user_id,name', 'toAccount:id,user_id,name', 'category:id,key,name'])
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

    /** Stop spreadsheet formula injection from notes typed in the app or imported. */
    private function safeCell(?string $v): ?string
    {
        return $v !== null && $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$v : $v;
    }
}
