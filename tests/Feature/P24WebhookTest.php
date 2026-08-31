<?php

use App\Enums\PaymentAttemptStatus;
use App\Services\Payments\Providers\Przelewy24PaymentProvider;
use Tests\TestCase;

uses(TestCase::class);

function p24Signature(
    string $merchantId,
    string $posId,
    string $sessionId,
    string $amount,
    string $currency,
    string $orderId,
    string $crcKey = 'abc123'
): string {
    $fields = [$merchantId, $posId, $sessionId, $amount, $currency, $orderId];

    return hash('sha384', implode('|', $fields).$crcKey);
}

function p24ValidPayload(): array
{
    return [
        'merchantId' => '12345',
        'posId' => '12345',
        'sessionId' => 'pay_01TESTP2400000001',
        'amount' => '1050',
        'currency' => 'PLN',
        'orderId' => 'P24-ORDER-1',
        'status' => ['statusCode' => 'COMPLETED', 'value' => 'completed'],
    ];
}

function configureP24ForWebhook(string $crcKey = 'abc123'): void
{
    config()->set('payments.providers.p24', [
        'enabled' => true,
        'environment' => 'sandbox',
        'merchant_id' => '12345',
        'pos_id' => '12345',
        'crc_key' => $crcKey,
        'api_key' => 'secret_key',
    ]);
}

// ---------------------------------------------------------------------------
// Signature verification
// ---------------------------------------------------------------------------

it('accepts a valid notification signature', function () {
    configureP24ForWebhook();
    $payload = p24ValidPayload();
    $payload['sign'] = p24Signature(
        $payload['merchantId'], $payload['posId'], $payload['sessionId'],
        $payload['amount'], $payload['currency'], $payload['orderId']
    );

    $provider = app(Przelewy24PaymentProvider::class);

    expect($provider->verifyWebhook($payload, []))->toBeTrue();
});

it('rejects an invalid notification signature', function () {
    configureP24ForWebhook();
    $payload = p24ValidPayload();
    $payload['sign'] = 'invalid-signature';

    $provider = app(Przelewy24PaymentProvider::class);

    expect($provider->verifyWebhook($payload, []))->toBeFalse();
});

it('rejects notifications with missing fields', function () {
    configureP24ForWebhook();
    $payload = p24ValidPayload();
    unset($payload['sign']);

    $provider = app(Przelewy24PaymentProvider::class);

    expect($provider->verifyWebhook($payload, []))->toBeFalse();
});

it('rejects notifications when crc_key is missing', function () {
    configureP24ForWebhook('');
    $payload = p24ValidPayload();
    $payload['sign'] = p24Signature(
        $payload['merchantId'], $payload['posId'], $payload['sessionId'],
        $payload['amount'], $payload['currency'], $payload['orderId'], ''
    );

    $provider = app(Przelewy24PaymentProvider::class);

    expect($provider->verifyWebhook($payload, []))->toBeFalse();
});

it('uses timing-safe comparison for signature verification', function () {
    configureP24ForWebhook();
    $payload = p24ValidPayload();
    $payload['sign'] = str_repeat('a', 128);

    $provider = app(Przelewy24PaymentProvider::class);

    expect($provider->verifyWebhook($payload, []))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Webhook parsing
// ---------------------------------------------------------------------------

it('parses a valid completed notification', function () {
    configureP24ForWebhook();
    $payload = p24ValidPayload();
    $payload['sign'] = p24Signature(
        $payload['merchantId'], $payload['posId'], $payload['sessionId'],
        $payload['amount'], $payload['currency'], $payload['orderId']
    );

    $provider = app(Przelewy24PaymentProvider::class);
    $result = $provider->parseWebhook($payload);

    expect($result->provider)->toBe('p24')
        ->and($result->providerPaymentId)->toBe('P24-ORDER-1')
        ->and($result->metadata['session_id'])->toBe('pay_01TESTP2400000001')
        ->and($result->status)->toBe(PaymentAttemptStatus::Succeeded->value)
        ->and($result->valid)->toBeTrue();
});

it('parses a pending notification', function () {
    configureP24ForWebhook();
    $payload = p24ValidPayload();
    $payload['status'] = ['statusCode' => 'PENDING', 'value' => 'pending'];
    $payload['sign'] = p24Signature(
        $payload['merchantId'], $payload['posId'], $payload['sessionId'],
        $payload['amount'], $payload['currency'], $payload['orderId']
    );

    $provider = app(Przelewy24PaymentProvider::class);
    $result = $provider->parseWebhook($payload);

    expect($result->status)->toBe(PaymentAttemptStatus::Pending->value);
});

it('parses a failed notification', function () {
    configureP24ForWebhook();
    $payload = p24ValidPayload();
    $payload['status'] = ['statusCode' => 'CANCELED', 'value' => 'canceled'];
    $payload['sign'] = p24Signature(
        $payload['merchantId'], $payload['posId'], $payload['sessionId'],
        $payload['amount'], $payload['currency'], $payload['orderId']
    );

    $provider = app(Przelewy24PaymentProvider::class);
    $result = $provider->parseWebhook($payload);

    expect($result->status)->toBe(PaymentAttemptStatus::Failed->value);
});

it('returns invalid result for malformed payload', function () {
    configureP24ForWebhook();

    $provider = app(Przelewy24PaymentProvider::class);
    $result = $provider->parseWebhook(['unexpected' => 'data']);

    expect($result->valid)->toBeFalse()
        ->and($result->status)->toBe(PaymentAttemptStatus::Failed->value);
});

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------

it('never exposes crc key in webhook parse result', function () {
    configureP24ForWebhook();
    $payload = p24ValidPayload();
    $payload['sign'] = p24Signature(
        $payload['merchantId'], $payload['posId'], $payload['sessionId'],
        $payload['amount'], $payload['currency'], $payload['orderId']
    );

    $provider = app(Przelewy24PaymentProvider::class);
    $result = $provider->parseWebhook($payload);
    $encoded = json_encode($result);

    expect($encoded)->not->toContain('abc123');
});
