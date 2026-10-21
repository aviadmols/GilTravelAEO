<?php

use App\Http\Controllers\GoogleConnectController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Middleware\DashboardAuth;
use Illuminate\Support\Facades\Route;

Route::middleware(DashboardAuth::class)->group(function () {
    Route::get('/', ReportController::class)->name('report');
    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'update']);
    Route::post('/settings/google/disconnect', [SettingsController::class, 'disconnectGoogle'])->name('google.disconnect');
});

// Search Console connection. Starts from a signed link, so it works without the dashboard login.
Route::get('/google/connect', [GoogleConnectController::class, 'show'])->middleware('signed')->name('google.connect');
Route::get('/google/callback', [GoogleConnectController::class, 'callback'])->name('google.callback');
Route::get('/google/property', [GoogleConnectController::class, 'property'])->name('google.property');
Route::post('/google/property', [GoogleConnectController::class, 'saveProperty']);
