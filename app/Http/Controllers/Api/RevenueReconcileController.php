<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Revenue\ReconcileRevenue;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RevenueReconcileController extends Controller
{
    public function __invoke(ReconcileRevenue $action): JsonResponse
    {
        return response()->json($action->execute());
    }
}
