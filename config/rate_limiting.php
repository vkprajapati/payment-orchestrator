<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| API Rate Limit Configuration
|--------------------------------------------------------------------------
|
| Centralized rate-limit bucket definitions. Each bucket maps to a Laravel
| RateLimiter::for() callback registered in AppServiceProvider. The
| ThrottleApiRequests middleware resolves the limiter by name from a
| route parameter, so limits are configured here — never hardcoded in
| controllers.
|
| Bucket strategy:
|
|   standard  — reads and ordinary operations (list, retrieve). High
|               generous limits; tenant-isolated per merchant.
|   sensitive — state-changing writes (create payment, process payment,
|               create refund). Stricter limits to protect against abuse.
|   export    — expensive read operations (audit log export). Strict
|               limits because a single request can scan and stream a
|               large number of rows.
|   unauthenticated — conservative fallback for requests that reach the
|               rate-limiting layer without a valid merchant context
|               (invalid/missing API key). IP-based, privacy-safe, and
|               low enough that an attacker cannot exhaust resources.
|
| Limits are expressed as "max attempts per decay minutes".
|
*/

return [

    'buckets' => [
        'unauthenticated' => [
            'max_attempts' => 10,
            'decay_minutes' => 1,
            'key_prefix' => 'unauth',
        ],
        'standard' => [
            'max_attempts' => 1200,
            'decay_minutes' => 1,
            'key_prefix' => 'std',
        ],
        'sensitive' => [
            'max_attempts' => 60,
            'decay_minutes' => 1,
            'key_prefix' => 'sensitive',
        ],
        'export' => [
            'max_attempts' => 30,
            'decay_minutes' => 1,
            'key_prefix' => 'export',
        ],
    ],

];
