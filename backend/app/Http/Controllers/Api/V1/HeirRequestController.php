<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HeirService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeirRequestController extends Controller
{
    /** Always the same answer, so the endpoint can't be used to discover heirs. */
    public function store(Request $request, HeirService $service): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $service->request($data['email'], $request->ip());

        return response()->json(['ok' => true]);
    }
}
