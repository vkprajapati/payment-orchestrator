<?php

use App\Http\Controllers\Api\V1\ApiContextController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\PaymentAttemptController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentProcessingController;
use App\Http\Controllers\Api\V1\PaymentWebhookController;
use App\Http\Controllers\Api\V1\RefundController;
use Illuminate\Support\Facades\Route;

// Audit export gets its own rate-limit bucket (expensive read). It is
// registered BEFORE the /audit-events/{reference} route so "export" is
// never captured as a reference.
Route::middleware(['api.key', 'throttle:export'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/audit-events/export', [AuditEventController::class, 'export'])
            ->name('api.v1.audit-events.export');
    });

Route::middleware(['api.key', 'throttle:standard'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/me', [ApiContextController::class, 'show'])
            ->name('api.v1.me');

        Route::get('/audit-events', [AuditEventController::class, 'index'])
            ->name('api.v1.audit-events.index');

        // Metrics is a cheap aggregate read: it stays on the standard
        // bucket (no dedicated bucket justified) and is registered BEFORE
        // /audit-events/{reference} so "metrics" is never a reference.
        Route::get('/audit-events/metrics', [AuditEventController::class, 'metrics'])
            ->name('api.v1.audit-events.metrics');

        // Health is a global, aggregate-only operational read (same cheap
        // standard bucket), registered BEFORE /audit-events/{reference}
        // so "health" is never a reference.
        Route::get('/audit-events/health', [AuditEventController::class, 'health'])
            ->name('api.v1.audit-events.health');

        Route::get('/audit-events/{reference}', [AuditEventController::class, 'show'])
            ->name('api.v1.audit-events.show');

        // API key lifecycle — static before parameterized, reads on the
        // cheap standard bucket; write mutations on sensitive.
        Route::get('/api-keys', [ApiKeyController::class, 'index'])
            ->name('api.v1.api-keys.index');
        Route::get('/api-keys/{reference}', [ApiKeyController::class, 'show'])
            ->name('api.v1.api-keys.show');

        Route::get('/payments', [PaymentController::class, 'index'])
            ->name('api.v1.payments.index');

        Route::get('/payments/{reference}', [PaymentController::class, 'show'])
            ->name('api.v1.payments.show');

        Route::get('/payments/{reference}/refunds', [RefundController::class, 'index'])
            ->name('api.v1.payments.refunds.index');

        Route::get('/payments/{reference}/refunds/{refundReference}', [RefundController::class, 'show'])
            ->name('api.v1.payments.refunds.show');
    });

Route::middleware(['api.key', 'throttle:sensitive'])
    ->prefix('v1')
    ->group(function () {
        Route::post('/payments', [PaymentController::class, 'store'])
            ->name('api.v1.payments.store');

        Route::post('/payments/{reference}/attempts', [PaymentAttemptController::class, 'store'])
            ->name('api.v1.payments.attempts.store');

        Route::post('/payments/{reference}/refunds', [RefundController::class, 'store'])
            ->name('api.v1.payments.refunds.store');

        Route::post('/payments/{reference}/attempts/{attempt}/execute', [PaymentAttemptController::class, 'execute'])
            ->name('api.v1.payments.attempts.execute');

        Route::post('/payments/{reference}/process', [PaymentProcessingController::class, 'process'])
            ->name('api.v1.payments.process');

        // API key lifecycle mutations — sensitive bucket.
        Route::post('/api-keys', [ApiKeyController::class, 'store'])
            ->name('api.v1.api-keys.store');
        Route::post('/api-keys/{reference}/revoke', [ApiKeyController::class, 'revoke'])
            ->name('api.v1.api-keys.revoke');
    });

/*
 * Provider webhooks — intentionally OUTSIDE the api.key and throttle
 * middleware groups: callers are external payment providers, not merchant
 * API clients. Authentication happens via provider-specific verification
 * inside PaymentWebhookController.
 */
Route::post('/v1/webhooks/{provider}', [PaymentWebhookController::class, 'handle'])
    ->name('api.v1.webhooks.handle');
