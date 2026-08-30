<?php

use App\Http\Controllers\MerchantSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
});

Route::middleware(['auth', 'verified', 'merchant'])->group(function () {
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    Route::get('/settings/workspace', [MerchantSettingsController::class, 'edit'])
        ->name('settings.workspace.edit');

    Route::put('/settings/workspace', [MerchantSettingsController::class, 'update'])
        ->name('settings.workspace.update');
});
