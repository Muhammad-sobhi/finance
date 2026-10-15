<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /** GET /reports?period=month&month=YYYY-MM  |  ?period=year&year=YYYY */
    public function __invoke(Request $request, ReportService $reports): JsonResponse
    {
        $data = $request->validate([
            'period' => ['nullable', 'in:month,year'],
            'month' => ['nullable', 'date_format:Y-m'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);
        $period = $data['period'] ?? 'month';
        $value = $period === 'year' ? (string) ($data['year'] ?? now()->year) : ($data['month'] ?? now()->format('Y-m'));

        return response()->json($reports->build($request->user(), $period, $value));
    }
}
