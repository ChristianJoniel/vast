<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Revenue\BuildDashboard;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class RevenueDashboardController extends Controller
{
    public function __invoke(BuildDashboard $action): JsonResponse
    {
        return response()->json($action->execute());
    }
}
