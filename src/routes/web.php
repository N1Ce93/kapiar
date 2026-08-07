<?php

use App\Http\Controllers\MonitoringStatsController;
use App\Http\Controllers\SiteAccessController;
use App\Http\Middleware\EnsureSiteAccess;
use Illuminate\Support\Facades\Route;

Route::get('/access', [SiteAccessController::class, 'show'])->name('access.show');
Route::post('/access', [SiteAccessController::class, 'store'])->name('access.store');

Route::middleware(EnsureSiteAccess::class)->group(function (): void {
    Route::redirect('/', '/sites');
    Route::get('/sites', [MonitoringStatsController::class, 'sites'])->name('monitoring.sites');
    Route::get('/sites/{site}/posts', [MonitoringStatsController::class, 'sitePosts'])->name('monitoring.sites.posts');
    Route::get('/telegram', [MonitoringStatsController::class, 'telegram'])->name('monitoring.telegram');
    Route::get('/telegram/{channel}/posts', [MonitoringStatsController::class, 'telegramPosts'])->name('monitoring.telegram.posts');
    Route::post('/logout', [SiteAccessController::class, 'destroy'])->name('access.logout');
});
