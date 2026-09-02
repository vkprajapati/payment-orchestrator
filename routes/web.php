<?php

use App\Http\Controllers\ApiKeyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MerchantSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::middleware(['auth', 'verified', 'merchant'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');

    Route::get('/settings/workspace', [MerchantSettingsController::class, 'edit'])
        ->name('settings.workspace.edit');

    Route::put('/settings/workspace', [MerchantSettingsController::class, 'update'])
        ->name('settings.workspace.update');

    Route::get('/settings/api-keys', [ApiKeyController::class, 'index'])
        ->name('settings.api-keys.index');

    Route::post('/settings/api-keys', [ApiKeyController::class, 'store'])
        ->name('settings.api-keys.store');

    Route::get('/settings/api-keys/{apiKey}/created', [ApiKeyController::class, 'created'])
        ->name('settings.api-keys.created');

    Route::put('/settings/api-keys/{apiKey}/scopes', [ApiKeyController::class, 'updateScopes'])
        ->name('settings.api-keys.scopes');

    Route::post('/settings/api-keys/{apiKey}/rotate', [ApiKeyController::class, 'rotate'])
        ->name('settings.api-keys.rotate');

    Route::get('/settings/api-keys/{apiKey}', [ApiKeyController::class, 'show'])
        ->name('settings.api-keys.show');

    Route::delete('/settings/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])
        ->name('settings.api-keys.destroy');
});
