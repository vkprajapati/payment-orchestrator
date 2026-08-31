<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment Providers
    |--------------------------------------------------------------------------
    |
    | Central configuration for payment provider integrations. Secrets are
    | always sourced from environment variables and are never exposed by
    | resources, exceptions, logs, or API responses.
    |
    | A provider only becomes available (supports the charge operation and
    | participates in routing) when it is both enabled AND possesses the
    | credentials it requires. Placeholder providers (p24, razorpay, payu)
    | remain disabled until their integrations are implemented.
    |
    */

    'providers' => [
        'stripe' => [
            'enabled' => env('PAYMENT_STRIPE_ENABLED', false),
            'secret_key' => env('STRIPE_SECRET_KEY'),
            'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        ],

        'p24' => [
            'enabled' => env('PAYMENT_P24_ENABLED', false),
        ],

        'razorpay' => [
            'enabled' => env('PAYMENT_RAZORPAY_ENABLED', false),
        ],

        'payu' => [
            'enabled' => env('PAYMENT_PAYU_ENABLED', false),
        ],
    ],

];
