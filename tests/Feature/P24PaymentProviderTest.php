<?php

use App\Contracts\Payments\PaymentProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Services\Payments\Providers\Przelewy24PaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Configure P24 provider credentials for testing.
 */
if (! function_exists('configureP24')) {
    function configureP24(bool $enabled = true): void
    {
        config()->set('payments.providers.p24', [
            'enabled' => $enabled,
            'environment' => 'sandbox',
            'merchant_id' => '12345',
            'pos_id' => '12345',
            'crc_key' => 'abc123',
            'api_key' => 'secret_key',
            'notify_url' => 'https://example.com/webhook/p24',
            'return_url' => 'https://example.com/return',
        ]);
    }
}

if (! function_exists('fakeP24Http')) {
    function fakeP24Http(string $orderId = 'ORDER-12345', int $status = 200, ?array $extra = null): void
    {
        Http::fake([
            'sandbox.przelewy24.pl/api/v1/oauth/authorize' => Http::response(['access_token' => 'fake-token'], 200),
            'sandbox.przelewy24.pl/api/v1/transaction/register' => Http::response(array_merge([
                'status' => ['statusCode' => 'SUCCESS'],
                'orderId' => $orderId,
                'redirectUri' => 'https://sandbox.przelewy24.pl/pay/'.$orderId,
            ], $extra ?? []), $status),
        ]);
    }
}

it('exposes the stable p24 identifier', function () {
    configureP24();

    expect(app(Przelewy24PaymentProvider::class)->name())->toBe('p24');
});

it('charges fail in a controlled way when p24 is not configured', function () {
    configureP24(false);
    $payment = Payment::factory()->create();

    app(Przelewy24PaymentProvider::class)->charge($payment);
})->throws(PaymentProviderException::class, 'is not configured yet');

it('supports charge when enabled and properly configured', function () {
    configureP24();

    expect(app(Przelewy24PaymentProvider::class)->supports(PaymentProvider::OPERATION_CHARGE))->toBeTrue()
        ->and(app(Przelewy24PaymentProvider::class)->supports('refund'))->toBeFalse();
});

it('returns a pending result with redirect URL on successful transaction registration', function () {
    configureP24();
    $payment = Payment::factory()->create([
        'amount' => 1050,
        'currency' => 'USD',
        'reference' => 'pay_01TESTP2400000001',
    ]);

    fakeP24Http('ORDER-12345');

    $result = app(Przelewy24PaymentProvider::class)->charge($payment);

    expect($result)->toBeInstanceOf(PaymentProviderResult::class)
        ->and($result->success)->toBeFalse()
        ->and($result->provider)->toBe('p24')
        ->and($result->providerPaymentId)->toBe('ORDER-12345')
        ->and($result->status)->toBe(PaymentStatus::Pending->value)
        ->and($result->metadata['redirect_uri'])->toBe('https://sandbox.przelewy24.pl/pay/ORDER-12345');
});

it('sends correct amount and currency to P24', function () {
    configureP24();
    $payment = Payment::factory()->create([
        'amount' => 2500,
        'currency' => 'EUR',
        'reference' => 'pay_01TESTP2400000002',
    ]);

    fakeP24Http('ORDER-67890');

    app(Przelewy24PaymentProvider::class)->charge($payment);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'transaction/register')) {
            return false;
        }

        $body = $request->data();

        return $body['amount'] === 2500
            && $body['currency'] === 'EUR'
            && $body['sessionId'] === 'pay_01TESTP2400000002';
    });
});

it('handles failed transaction registration', function () {
    configureP24();
    $payment = Payment::factory()->create();

    fakeP24Http('ORDER-12345', 200, ['status' => ['statusCode' => 'ERROR', 'code' => 'ERR_INVALID_AMOUNT']]);

    app(Przelewy24PaymentProvider::class)->charge($payment);
})->throws(PaymentProviderException::class, 'could not process the charge');

it('handles HTTP errors during transaction registration', function () {
    configureP24();
    $payment = Payment::factory()->create();

    Http::fake([
        'sandbox.przelewy24.pl/api/v1/oauth/authorize' => Http::response(['access_token' => 'fake-token'], 200),
        'sandbox.przelewy24.pl/api/v1/transaction/register' => Http::response(null, 500),
    ]);

    app(Przelewy24PaymentProvider::class)->charge($payment);
})->throws(PaymentProviderException::class, 'could not process the charge');

it('handles malformed provider response', function () {
    configureP24();
    $payment = Payment::factory()->create();

    Http::fake([
        'sandbox.przelewy24.pl/api/v1/oauth/authorize' => Http::response(['access_token' => 'fake-token'], 200),
        'sandbox.przelewy24.pl/api/v1/transaction/register' => Http::response('invalid json', 200),
    ]);

    app(Przelewy24PaymentProvider::class)->charge($payment);
})->throws(PaymentProviderException::class, 'could not process the charge');

it('never exposes secrets in the result metadata', function () {
    configureP24();
    $payment = Payment::factory()->create();

    fakeP24Http('ORDER-SECRET');

    $result = app(Przelewy24PaymentProvider::class)->charge($payment);
    $metadata = json_encode($result->metadata);

    expect($metadata)->not->toContain('secret_key')
        ->and($metadata)->not->toContain('abc123');
});
