<?php

use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Services\Payments\Providers\PayUPaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function configurePayU(bool $enabled = true, ?string $clientId = 'test_client', ?string $clientSecret = 'test_secret', ?string $posId = 'test_pos'): void
{
    config([
        'payments.providers.payu.enabled' => $enabled,
        'payments.providers.payu.environment' => 'sandbox',
        'payments.providers.payu.client_id' => $clientId,
        'payments.providers.payu.client_secret' => $clientSecret,
        'payments.providers.payu.merchant_pos_id' => $posId,
        'payments.providers.payu.continue_url' => 'https://example.com/continue',
        'payments.providers.payu.notify_url' => 'https://example.com/notify',
    ]);
}

it('charges fail in a controlled way when PayU is not configured', function () {
    configurePayU(false);

    $payment = Payment::factory()->create();

    app(PayUPaymentProvider::class)->charge($payment);
})->throws(PaymentProviderException::class, 'is not configured yet');

it('acquires OAuth token and creates order successfully', function () {
    configurePayU();
    $payment = Payment::factory()->create([
        'amount' => 1050,
        'currency' => 'USD',
        'reference' => 'pay_test_001',
        'description' => 'Test payment',
    ]);

    Http::fake([
        '*/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'fake_token_123']),
        '*/api/v2_1/orders' => Http::response([
            'orderId' => 'PAYU_ORDER_001',
            'status' => 'COMPLETED',
            'redirectUri' => 'https://payu.com/checkout/PAYU_ORDER_001',
        ], 200),
    ]);

    $result = app(PayUPaymentProvider::class)->charge($payment);

    expect($result)->toBeInstanceOf(PaymentProviderResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->provider)->toBe('payu')
        ->and($result->providerPaymentId)->toBe('PAYU_ORDER_001')
        ->and($result->status)->toBe(PaymentStatus::Succeeded->value)
        ->and($result->metadata['redirect_uri'])->toBe('https://payu.com/checkout/PAYU_ORDER_001');
});

it('maps pending status to pending result', function () {
    configurePayU();
    $payment = Payment::factory()->create([
        'amount' => 2500,
        'currency' => 'EUR',
        'reference' => 'pay_test_002',
    ]);

    Http::fake([
        '*/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'fake_token_123']),
        '*/api/v2_1/orders' => Http::response([
            'orderId' => 'PAYU_ORDER_002',
            'status' => 'PENDING',
            'redirectUri' => 'https://payu.com/checkout/PAYU_ORDER_002',
        ], 200),
    ]);

    $result = app(PayUPaymentProvider::class)->charge($payment);

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(PaymentStatus::Pending->value)
        ->and($result->providerPaymentId)->toBe('PAYU_ORDER_002');
});

it('maps canceled status to failed result', function () {
    configurePayU();
    $payment = Payment::factory()->create([
        'amount' => 500,
        'currency' => 'PLN',
        'reference' => 'pay_test_003',
    ]);

    Http::fake([
        '*/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'fake_token_123']),
        '*/api/v2_1/orders' => Http::response([
            'orderId' => 'PAYU_ORDER_003',
            'status' => 'CANCELED',
        ], 200),
    ]);

    $result = app(PayUPaymentProvider::class)->charge($payment);

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(PaymentStatus::Failed->value);
});
it('handles OAuth failure gracefully', function () {
    configurePayU();
    $payment = Payment::factory()->create();

    Http::fake([
        '*/pl/standard/user/oauth/authorize' => Http::response(['error' => 'invalid_client'], 401),
    ]);

    app(PayUPaymentProvider::class)->charge($payment);
})->throws(PaymentProviderException::class, 'could not process the charge');

it('handles API failure gracefully', function () {
    configurePayU();
    $payment = Payment::factory()->create();

    Http::fake([
        '*/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'fake_token_123']),
        '*/api/v2_1/orders' => Http::response(['error' => 'internal_error'], 500),
    ]);

    app(PayUPaymentProvider::class)->charge($payment);
})->throws(PaymentProviderException::class, 'could not process the charge');

it('handles malformed OAuth response', function () {
    configurePayU();
    $payment = Payment::factory()->create();

    Http::fake([
        '*/pl/standard/user/oauth/authorize' => Http::response(['token_type' => 'bearer'], 200),
    ]);

    app(PayUPaymentProvider::class)->charge($payment);
})->throws(PaymentProviderException::class, 'could not process the charge');

it('passes buyer metadata to order request', function () {
    configurePayU();
    $payment = Payment::factory()->create([
        'reference' => 'pay_test_buyer',
    ]);

    Http::fake([
        '*/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'fake_token_123']),
        '*/api/v2_1/orders' => Http::response([
            'orderId' => 'PAYU_ORDER_BUYER',
            'status' => 'COMPLETED',
        ], 200),
    ]);

    app(PayUPaymentProvider::class)->charge($payment, [
        'buyer' => [
            'email' => 'john@example.com',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'language' => 'pl',
        ],
    ]);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'api/v2_1/orders')) {
            return false;
        }

        $body = $request->data();

        return $body['buyer']['email'] === 'john@example.com'
            && $body['buyer']['firstName'] === 'John'
            && $body['buyer']['lastName'] === 'Doe'
            && $body['buyer']['language'] === 'pl';
    });
});

it('never exposes secrets in result metadata', function () {
    configurePayU();
    $payment = Payment::factory()->create();

    Http::fake([
        '*/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'fake_token_123']),
        '*/api/v2_1/orders' => Http::response([
            'orderId' => 'PAYU_ORDER_004',
            'status' => 'COMPLETED',
        ], 200),
    ]);

    $result = app(PayUPaymentProvider::class)->charge($payment);

    $metadata = json_encode($result->metadata);

    expect($metadata)->not->toContain('test_secret')
        ->and($metadata)->not->toContain('fake_token_123');
});

it('verifies order request structure', function () {
    configurePayU();
    $payment = Payment::factory()->create([
        'amount' => 1050,
        'currency' => 'USD',
        'reference' => 'pay_test_001',
        'description' => 'Test payment',
    ]);

    Http::fake([
        '*/pl/standard/user/oauth/authorize' => Http::response(['access_token' => 'fake_token_123']),
        '*/api/v2_1/orders' => Http::response([
            'orderId' => 'PAYU_ORDER_001',
            'status' => 'COMPLETED',
        ], 200),
    ]);

    app(PayUPaymentProvider::class)->charge($payment);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'api/v2_1/orders')) {
            return false;
        }

        $body = $request->data();

        return $body['extOrderId'] === 'pay_test_001'
            && $body['totalAmount'] === '1050'
            && $body['currencyCode'] === 'USD'
            && $body['merchantPosId'] === 'test_pos'
            && $body['continueUrl'] === 'https://example.com/continue'
            && $body['notifyUrl'] === 'https://example.com/notify';
    });
});
