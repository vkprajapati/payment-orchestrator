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
            'environment' => env('PAYMENT_P24_ENVIRONMENT', 'sandbox'),
            'merchant_id' => env('P24_MERCHANT_ID'),
            'pos_id' => env('P24_POS_ID'),
            'api_key' => env('P24_API_KEY'),
            'crc_key' => env('P24_CRC_KEY'),
            'notify_url' => env('P24_NOTIFY_URL'),
            'return_url' => env('P24_RETURN_URL'),
        ],

        'razorpay' => [
            'enabled' => env('PAYMENT_RAZORPAY_ENABLED', false),
        ],

        'payu' => [
            'enabled' => env('PAYMENT_PAYU_ENABLED', false),
            'environment' => env('PAYMENT_PAYU_ENVIRONMENT', 'sandbox'),
            'client_id' => env('PAYU_CLIENT_ID'),
            'client_secret' => env('PAYU_CLIENT_SECRET'),
            'merchant_pos_id' => env('PAYU_MERCHANT_POS_ID'),
            'second_key' => env('PAYU_SECOND_KEY'),
            'notify_url' => env('PAYU_NOTIFY_URL'),
            'continue_url' => env('PAYU_CONTINUE_URL'),
        ],
    ],

];
