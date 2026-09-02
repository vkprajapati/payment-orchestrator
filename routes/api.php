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

// Scope enforcement order: api.key authenticates first, then the scope
// middleware authorizes, then throttling applies. Authorization failures
// therefore never consume the merchant's rate-limit bucket, while all
// authorized traffic remains fully rate-limited. Multiple scopes on one
// route are alternatives (any-of) — none of these routes need that today,
// but the middleware supports it.
//
// Static audit routes stay registered BEFORE /audit-events/{reference}.

// Audit export — dedicated export bucket (expensive read).
Route::middleware(['api.key', 'scope:audit:read', 'throttle:export'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/audit-events/export', [AuditEventController::class, 'export'])
            ->name('api.v1.audit-events.export');
    });

// Identity: account:read is the minimal "who am I" permission and implies
// no domain data access.
Route::middleware(['api.key', 'scope:account:read', 'throttle:standard'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/me', [ApiContextController::class, 'show'])
            ->name('api.v1.me');
    });

// Audit reads.
Route::middleware(['api.key', 'scope:audit:read', 'throttle:standard'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/audit-events', [AuditEventController::class, 'index'])
            ->name('api.v1.audit-events.index');

        // Cheap aggregate reads registered BEFORE /audit-events/{reference}
        // so "metrics"/"health" are never captured as a reference.
        Route::get('/audit-events/metrics', [AuditEventController::class, 'metrics'])
            ->name('api.v1.audit-events.metrics');

        Route::get('/audit-events/health', [AuditEventController::class, 'health'])
            ->name('api.v1.audit-events.health');

        Route::get('/audit-events/{reference}', [AuditEventController::class, 'show'])
            ->name('api.v1.audit-events.show');
    });

// API key metadata reads.
Route::middleware(['api.key', 'scope:api_keys:read', 'throttle:standard'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/api-keys', [ApiKeyController::class, 'index'])
            ->name('api.v1.api-keys.index');
        Route::get('/api-keys/{reference}', [ApiKeyController::class, 'show'])
            ->name('api.v1.api-keys.show');
    });

// Payment reads.
Route::middleware(['api.key', 'scope:payments:read', 'throttle:standard'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/payments', [PaymentController::class, 'index'])
            ->name('api.v1.payments.index');

        Route::get('/payments/{reference}', [PaymentController::class, 'show'])
            ->name('api.v1.payments.show');
    });

// Refund reads.
Route::middleware(['api.key', 'scope:refunds:read', 'throttle:standard'])
    ->prefix('v1')
    ->group(function () {
        Route::get('/payments/{reference}/refunds', [RefundController::class, 'index'])
            ->name('api.v1.payments.refunds.index');

        Route::get('/payments/{reference}/refunds/{refundReference}', [RefundController::class, 'show'])
            ->name('api.v1.payments.refunds.show');
    });

// Payment creation.
Route::middleware(['api.key', 'scope:payments:write', 'throttle:sensitive'])
    ->prefix('v1')
    ->group(function () {
        Route::post('/payments', [PaymentController::class, 'store'])
            ->name('api.v1.payments.store');
    });

// Payment processing and attempts.
Route::middleware(['api.key', 'scope:payments:process', 'throttle:sensitive'])
    ->prefix('v1')
    ->group(function () {
        Route::post('/payments/{reference}/attempts', [PaymentAttemptController::class, 'store'])
            ->name('api.v1.payments.attempts.store');

        Route::post('/payments/{reference}/attempts/{attempt}/execute', [PaymentAttemptController::class, 'execute'])
            ->name('api.v1.payments.attempts.execute');

        Route::post('/payments/{reference}/process', [PaymentProcessingController::class, 'process'])
            ->name('api.v1.payments.process');
    });

// Refund creation.
Route::middleware(['api.key', 'scope:refunds:write', 'throttle:sensitive'])
    ->prefix('v1')
    ->group(function () {
        Route::post('/payments/{reference}/refunds', [RefundController::class, 'store'])
            ->name('api.v1.payments.refunds.store');
    });

// API key lifecycle mutations.
Route::middleware(['api.key', 'scope:api_keys:write', 'throttle:sensitive'])
    ->prefix('v1')
    ->group(function () {
        Route::post('/api-keys', [ApiKeyController::class, 'store'])
            ->name('api.v1.api-keys.store');
        Route::post('/api-keys/{reference}/revoke', [ApiKeyController::class, 'revoke'])
            ->name('api.v1.api-keys.revoke');
        Route::post('/api-keys/{reference}/rotate', [ApiKeyController::class, 'rotate'])
            ->name('api.v1.api-keys.rotate');
        Route::put('/api-keys/{reference}/scopes', [ApiKeyController::class, 'updateScopes'])
            ->name('api.v1.api-keys.scopes.update');
    });

/*
 * Provider webhooks — intentionally OUTSIDE the api.key and throttle
 * middleware groups: callers are external payment providers, not merchant
 * API clients. Authentication happens via provider-specific verification
 * inside PaymentWebhookController.
 */
Route::post('/v1/webhooks/{provider}', [PaymentWebhookController::class, 'handle'])
    ->name('api.v1.webhooks.handle');
