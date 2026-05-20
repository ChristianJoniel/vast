<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Revenue\BuildDashboard;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(BuildDashboard $action): Response
    {
        return Inertia::render('Dashboard', [
            'revenueData' => $action->execute(),
        ]);
    }
}
