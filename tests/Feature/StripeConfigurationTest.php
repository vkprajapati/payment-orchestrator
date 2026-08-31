<?php

use App\Contracts\Payments\PaymentProvider;
use App\Services\Payments\Providers\StripePaymentProvider;
use Tests\TestCase;

uses(TestCase::class);

it('does not support charge when Stripe is disabled', function () {
    config(['payments.providers.stripe.enabled' => false]);

    $provider = app(StripePaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('does not support charge when enabled but the secret key is missing', function () {
    config([
        'payments.providers.stripe.enabled' => true,
        'payments.providers.stripe.secret_key' => null,
    ]);

    $provider = app(StripePaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('does not support charge when the secret key is an empty string', function () {
    config([
        'payments.providers.stripe.enabled' => true,
        'payments.providers.stripe.secret_key' => '',
    ]);

    $provider = app(StripePaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('supports charge when enabled and properly configured', function () {
    config([
        'payments.providers.stripe.enabled' => true,
        'payments.providers.stripe.secret_key' => 'sk_test_configured_key',
    ]);

    $provider = app(StripePaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeTrue();
});
