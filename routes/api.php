<?php

use App\Http\Controllers\Api\V1\ApiContextController;
use App\Http\Controllers\Api\V1\PaymentAttemptController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('api.key')
    ->prefix('v1')
    ->group(function () {
        Route::get('/me', [ApiContextController::class, 'show'])
            ->name('api.v1.me');

        Route::get('/payments', [PaymentController::class, 'index'])
            ->name('api.v1.payments.index');

        Route::post('/payments', [PaymentController::class, 'store'])
            ->name('api.v1.payments.store');

        Route::get('/payments/{reference}', [PaymentController::class, 'show'])
            ->name('api.v1.payments.show');

        Route::post('/payments/{reference}/attempts', [PaymentAttemptController::class, 'store'])
            ->name('api.v1.payments.attempts.store');

        Route::post('/payments/{reference}/attempts/{attempt}/execute', [PaymentAttemptController::class, 'execute'])
            ->name('api.v1.payments.attempts.execute');
    });

/*
 * Provider webhooks — intentionally OUTSIDE the api.key middleware
 * group: callers are external payment providers, not merchant API
 * clients. Authentication happens via provider-specific verification
 * inside PaymentWebhookController.
 */
Route::post('/v1/webhooks/{provider}', [PaymentWebhookController::class, 'handle'])
    ->name('api.v1.webhooks.handle');
