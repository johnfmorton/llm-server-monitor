<?php

use App\Http\Controllers\Api\SummaryController;
use App\Http\Middleware\AuthenticateServer;
use App\Http\Middleware\LimitRequestSize;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([LimitRequestSize::class, AuthenticateServer::class, 'throttle:ingest'])
    ->group(function () {
        Route::post('summaries', [SummaryController::class, 'store'])->name('api.summaries.store');
    });
