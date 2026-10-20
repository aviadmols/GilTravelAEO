<?php

use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\DashboardAuth;
use Illuminate\Support\Facades\Route;

Route::middleware(DashboardAuth::class)->group(function () {
    Route::get('/', ReportController::class)->name('report');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update']);
});
