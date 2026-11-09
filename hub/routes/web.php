<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ServerDayController;
use App\Http\Controllers\SessionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/servers/{server:name}/days/{date}', [ServerDayController::class, 'show'])
        ->where('date', '\d{4}-\d{2}-\d{2}')
        ->name('server-days.show');
    Route::get('/servers/{server:name}/days/{date}/summary.json', [ServerDayController::class, 'raw'])
        ->where('date', '\d{4}-\d{2}-\d{2}')
        ->name('server-days.raw');
    Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
});
