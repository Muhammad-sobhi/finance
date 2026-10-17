<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Bill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillController extends RecurringController
{
    protected function model(): string
    {
        return Bill::class;
    }

    protected function extraRules(bool $create): array
    {
        return ['due_date' => [$create ? 'required' : 'sometimes', 'date_format:Y-m-d']];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->items($request)->map(fn (Bill $b) => $this->present($b))]);
    }
}
