<?php

use App\Http\Controllers\Api\RevenueDashboardController;
use App\Http\Controllers\Api\RevenueImportController;
use App\Http\Controllers\Api\RevenueReconcileController;
use Illuminate\Support\Facades\Route;

Route::post('/revenue/import', RevenueImportController::class)->name('revenue.import');
Route::get('/revenue/reconcile', RevenueReconcileController::class)->name('revenue.reconcile');
Route::get('/revenue/dashboard', RevenueDashboardController::class)->name('revenue.dashboard');
