<?php

use App\Contracts\Payments\PaymentProvider;
use App\Services\Payments\Providers\Przelewy24PaymentProvider;
use Tests\TestCase;

uses(TestCase::class);

it('does not support charge when P24 is disabled', function () {
    config(['payments.providers.p24' => [
        'enabled' => false,
        'environment' => 'sandbox',
        'merchant_id' => '12345',
        'pos_id' => '12345',
        'crc_key' => 'abc123',
        'api_key' => 'secret_key',
        'notify_url' => 'https://example.com/webhook/p24',
        'return_url' => 'https://example.com/return',
    ]]);

    $provider = app(Przelewy24PaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('does not support charge when enabled but merchant_id is missing', function () {
    config(['payments.providers.p24' => [
        'enabled' => true,
        'environment' => 'sandbox',
        'merchant_id' => null,
        'pos_id' => '12345',
        'crc_key' => 'abc123',
        'api_key' => 'secret_key',
        'notify_url' => 'https://example.com/webhook/p24',
        'return_url' => 'https://example.com/return',
    ]]);

    $provider = app(Przelewy24PaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('does not support charge when enabled but crc_key is missing', function () {
    config(['payments.providers.p24' => [
        'enabled' => true,
        'environment' => 'sandbox',
        'merchant_id' => '12345',
        'pos_id' => '12345',
        'crc_key' => null,
        'api_key' => 'secret_key',
        'notify_url' => 'https://example.com/webhook/p24',
        'return_url' => 'https://example.com/return',
    ]]);

    $provider = app(Przelewy24PaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('supports charge when enabled and properly configured', function () {
    config(['payments.providers.p24' => [
        'enabled' => true,
        'environment' => 'sandbox',
        'merchant_id' => '12345',
        'pos_id' => '12345',
        'crc_key' => 'abc123',
        'api_key' => 'secret_key',
        'notify_url' => 'https://example.com/webhook/p24',
        'return_url' => 'https://example.com/return',
    ]]);

    $provider = app(Przelewy24PaymentProvider::class);

    expect($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeTrue();
});
