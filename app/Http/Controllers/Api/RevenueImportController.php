<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Revenue\ImportRevenuePayload;
use App\Http\Controllers\Controller;
use App\Http\Requests\Revenue\ImportRevenueRequest;
use Illuminate\Http\JsonResponse;

class RevenueImportController extends Controller
{
    public function __invoke(ImportRevenueRequest $request, ImportRevenuePayload $action): JsonResponse
    {
        $summary = $action->execute($request->validated());

        return response()->json($summary);
    }
}
