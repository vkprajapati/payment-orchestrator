<?php

use App\Contracts\Payments\PaymentProvider;
use App\Services\Payments\Providers\PayUPaymentProvider;
use Tests\TestCase;

uses(TestCase::class);

it('does not support charge when PayU is disabled', function () {
    config(['payments.providers.payu.enabled' => false]);

    $provider = app(PayUPaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('does not support charge when enabled but client_id is missing', function () {
    config([
        'payments.providers.payu.enabled' => true,
        'payments.providers.payu.client_id' => null,
        'payments.providers.payu.client_secret' => 'secret',
        'payments.providers.payu.merchant_pos_id' => 'pos123',
    ]);

    $provider = app(PayUPaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('does not support charge when enabled but client_secret is missing', function () {
    config([
        'payments.providers.payu.enabled' => true,
        'payments.providers.payu.client_id' => 'client123',
        'payments.providers.payu.client_secret' => null,
        'payments.providers.payu.merchant_pos_id' => 'pos123',
    ]);

    $provider = app(PayUPaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('does not support charge when enabled but merchant_pos_id is missing', function () {
    config([
        'payments.providers.payu.enabled' => true,
        'payments.providers.payu.client_id' => 'client123',
        'payments.providers.payu.client_secret' => 'secret',
        'payments.providers.payu.merchant_pos_id' => null,
    ]);

    $provider = app(PayUPaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('does not support charge when credentials are empty strings', function () {
    config([
        'payments.providers.payu.enabled' => true,
        'payments.providers.payu.client_id' => '',
        'payments.providers.payu.client_secret' => '',
        'payments.providers.payu.merchant_pos_id' => '',
    ]);

    $provider = app(PayUPaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('supports charge when enabled and properly configured', function () {
    config([
        'payments.providers.payu.enabled' => true,
        'payments.providers.payu.client_id' => 'client123',
        'payments.providers.payu.client_secret' => 'secret',
        'payments.providers.payu.merchant_pos_id' => 'pos123',
    ]);

    $provider = app(PayUPaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeTrue();
});

it('does not support refund operation', function () {
    config([
        'payments.providers.payu.enabled' => true,
        'payments.providers.payu.client_id' => 'client123',
        'payments.providers.payu.client_secret' => 'secret',
        'payments.providers.payu.merchant_pos_id' => 'pos123',
    ]);

    $provider = app(PayUPaymentProvider::class);

    expect($provider->supports('refund'))->toBeFalse();
});

it('uses sandbox URL by default', function () {
    config([
        'payments.providers.payu.enabled' => true,
        'payments.providers.payu.environment' => 'sandbox',
    ]);

    $provider = app(PayUPaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('exposes the stable payu identifier', function () {
    expect(app(PayUPaymentProvider::class)->name())->toBe('payu');
});
