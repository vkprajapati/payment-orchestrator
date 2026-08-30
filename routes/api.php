<?php

use App\Http\Controllers\Api\V1\ApiContextController;
use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/me', [ApiContextController::class, 'show'])
        ->middleware('api.key')
        ->name('api.v1.me');

    Route::post('/payments', [PaymentController::class, 'store'])
        ->middleware('api.key')
        ->name('api.v1.payments.store');
});
