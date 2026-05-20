<?php

use App\Http\Controllers\Api\RevenueImportController;
use Illuminate\Support\Facades\Route;

Route::post('/revenue/import', RevenueImportController::class)->name('revenue.import');
