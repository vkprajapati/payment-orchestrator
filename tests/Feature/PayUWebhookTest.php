<?php

use App\Data\Payments\PaymentProviderWebhookResult;
use App\Enums\PaymentAttemptStatus;
use App\Services\Payments\Providers\PayUPaymentProvider;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'payments.providers.payu.second_key' => 'test_second_key_123',
    ]);
});

it('verifies a valid webhook signature', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = ['order' => ['orderId' => 'PAYU_001', 'extOrderId' => 'pay_test_001', 'status' => 'COMPLETED']];
    $jsonPayload = json_encode($payload);
    $signature = hash('sha256', $jsonPayload.'test_second_key_123');

    expect($provider->verifyWebhook($payload, ['x-openpayu-signature' => $signature]))->toBeTrue();
});

it('rejects an invalid webhook signature', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = ['order' => ['orderId' => 'PAYU_001', 'extOrderId' => 'pay_test_001', 'status' => 'COMPLETED']];

    expect($provider->verifyWebhook($payload, ['x-openpayu-signature' => 'invalid_signature']))->toBeFalse();
});

it('rejects when signature header is missing', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = ['order' => ['orderId' => 'PAYU_001']];

    expect($provider->verifyWebhook($payload, []))->toBeFalse();
});

it('rejects when second_key is not configured', function () {
    config(['payments.providers.payu.second_key' => null]);

    $provider = app(PayUPaymentProvider::class);

    $payload = ['order' => ['orderId' => 'PAYU_001']];
    $jsonPayload = json_encode($payload);
    $signature = hash('sha256', $jsonPayload.'test_second_key_123');

    expect($provider->verifyWebhook($payload, ['x-openpayu-signature' => $signature]))->toBeFalse();
});

it('verifies webhook with array payload', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = ['order' => ['orderId' => 'PAYU_001', 'status' => 'COMPLETED']];
    $jsonPayload = json_encode($payload);
    $signature = hash('sha256', $jsonPayload.'test_second_key_123');

    expect($provider->verifyWebhook($payload, ['x-openpayu-signature' => $signature]))->toBeTrue();
});

it('parses a valid completed webhook', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = [
        'order' => [
            'orderId' => 'PAYU_ORDER_123',
            'extOrderId' => 'pay_test_ext_123',
            'status' => 'COMPLETED',
        ],
    ];

    $result = $provider->parseWebhook($payload, []);

    expect($result)->toBeInstanceOf(PaymentProviderWebhookResult::class)
        ->and($result->valid)->toBeTrue()
        ->and($result->provider)->toBe('payu')
        ->and($result->providerPaymentId)->toBe('PAYU_ORDER_123')
        ->and($result->metadata['external_id'])->toBe('pay_test_ext_123')
        ->and($result->event)->toBe('order.COMPLETED')
        ->and($result->status)->toBe(PaymentAttemptStatus::Succeeded->value);
});

it('parses a pending webhook', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = [
        'order' => [
            'orderId' => 'PAYU_ORDER_456',
            'extOrderId' => 'pay_test_ext_456',
            'status' => 'PENDING',
        ],
    ];

    $result = $provider->parseWebhook($payload, []);

    expect($result->valid)->toBeTrue()
        ->and($result->status)->toBe(PaymentAttemptStatus::Pending->value)
        ->and($result->event)->toBe('order.PENDING');
});

it('parses a canceled webhook', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = [
        'order' => [
            'orderId' => 'PAYU_ORDER_789',
            'extOrderId' => 'pay_test_ext_789',
            'status' => 'CANCELED',
        ],
    ];

    $result = $provider->parseWebhook($payload, []);

    expect($result->valid)->toBeTrue()
        ->and($result->status)->toBe(PaymentAttemptStatus::Failed->value)
        ->and($result->event)->toBe('order.CANCELED');
});

it('parses a waiting for confirmation webhook', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = [
        'order' => [
            'orderId' => 'PAYU_ORDER_WFC',
            'extOrderId' => 'pay_test_ext_wfc',
            'status' => 'WAITING_FOR_CONFIRMATION',
        ],
    ];

    $result = $provider->parseWebhook($payload, []);

    expect($result->valid)->toBeTrue()
        ->and($result->status)->toBe(PaymentAttemptStatus::Pending->value);
});

it('returns invalid result for malformed payload', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = ['invalid' => 'data'];

    $result = $provider->parseWebhook($payload, []);

    expect($result->valid)->toBeFalse()
        ->and($result->provider)->toBe('payu')
        ->and($result->event)->toBe('unknown');
});

it('returns invalid result for empty payload', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = [];

    $result = $provider->parseWebhook($payload, []);

    expect($result->valid)->toBeFalse()
        ->and($result->event)->toBe('unknown');
});

it('returns invalid result for payload without order key', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = ['event' => 'order.completed'];

    $result = $provider->parseWebhook($payload, []);

    expect($result->valid)->toBeFalse();
});

it('never exposes second_key in webhook result metadata', function () {
    $provider = app(PayUPaymentProvider::class);

    $payload = [
        'order' => [
            'orderId' => 'PAYU_ORDER_123',
            'status' => 'COMPLETED',
        ],
    ];

    $result = $provider->parseWebhook($payload, []);

    $metadata = json_encode($result->metadata);

    expect($metadata)->not->toContain('test_second_key_123');
});
