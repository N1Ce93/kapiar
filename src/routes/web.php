<?php

use App\Http\Controllers\MonitoringStatsController;
use Illuminate\Support\Facades\Route;


Route::redirect('/', '/sites');
Route::get('/sites', [MonitoringStatsController::class, 'sites'])->name('monitoring.sites');
Route::get('/telegram', [MonitoringStatsController::class, 'telegram'])->name('monitoring.telegram');
