<?php

use App\Http\Controllers\ReportController;
use App\Http\Middleware\DashboardAuth;
use Illuminate\Support\Facades\Route;

Route::get('/', ReportController::class)->middleware(DashboardAuth::class)->name('report');
