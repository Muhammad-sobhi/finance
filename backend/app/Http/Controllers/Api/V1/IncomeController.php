<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Models\IncomeSource;
use App\Models\Project;
use App\Models\Transaction;
use App\Services\CurrencyConverter;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Income overview: this month by kind (recurring / projects / other), expected, outstanding, 6-month series. */
class IncomeController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $user = $request->user();
        $fx = CurrencyConverter::for($user);
        $start = CarbonImmutable::createFromFormat('!Y-m', $request->input('month', now()->format('Y-m')));
        $end = $start->endOfMonth();
        $from = $start->subMonthsNoOverflow(5);

        $kind = fn (?string $source) => match ($source) {
            'income_source' => 'recurring',
            'project_payment' => 'projects',
            default => 'other',
        };
        $series = [];
        for ($m = $from; $m <= $start; $m = $m->addMonthNoOverflow()) {
            $series[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'recurring' => 0.0, 'projects' => 0.0, 'other' => 0.0];
        }
        Transaction::ownedBy($user)->cashflow()->where('type', TransactionType::Income->value)
            ->whereBetween('date', [$from->toDateString(), $end->toDateString()])
            ->get(['user_id', 'amount', 'currency', 'date', 'source_type'])
            ->each(function (Transaction $t) use (&$series, $fx, $kind) {
                $k = $kind($t->source_type);
                $m = $t->date->format('Y-m');
                $series[$m][$k] = round($series[$m][$k] + $fx->toBase($t->amount, $t->currency), 2);
            });
        $now = $series[$start->format('Y-m')];

        // Still expected this month: active recurring income whose next date falls in it.
        $expected = IncomeSource::ownedBy($user)->where('is_active', true)
            ->whereBetween('next_date', [$start->toDateString(), $end->toDateString()])->get()
            ->sum(fn (IncomeSource $s) => $fx->toBase($s->amount, $s->currency));
        $outstanding = Project::ownedBy($user)->where('status', 'active')->with('payments')->get()
            ->sum(fn (Project $p) => $fx->toBase($p->outstanding(), $p->currency));

        return response()->json([
            'month' => $start->format('Y-m'),
            'base_currency' => $fx->base,
            'received' => $now + ['total' => round($now['recurring'] + $now['projects'] + $now['other'], 2)],
            'expected' => round($expected, 2),
            'outstanding' => round($outstanding, 2),
            'series' => array_values($series),
        ]);
    }
}
