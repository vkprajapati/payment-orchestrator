<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Contracts\Payments\PaymentRoutingStrategy;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\Providers\MockPaymentProvider;
use App\Services\Payments\Providers\PayUPaymentProvider;
use App\Services\Payments\Providers\StripePaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $user = User::factory()->create();
    $user->merchants()->attach($this->merchant, ['role' => 'owner']);
    $this->rawKey = (new CreateApiKey)->create($this->merchant, 'test')->rawKey;
    $this->headers = ['Authorization' => 'Bearer '.$this->rawKey];
});

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

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

it('excludes p24 from routing when disabled', function () {
    configureP24(false);

    $payment = Payment::factory()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $names = app(PaymentRoutingStrategy::class)->resolveProviders($payment)->providers();

    expect($names)->toContain('mock')->not->toContain('p24');
});

it('excludes p24 from routing when enabled but credentials missing', function () {
    config(['payments.providers.p24.enabled' => true]);
    config(['payments.providers.p24.merchant_id' => null]);

    $payment = Payment::factory()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $names = app(PaymentRoutingStrategy::class)->resolveProviders($payment)->providers();

    expect($names)->toContain('mock')->not->toContain('p24');
});

it('places configured p24 after stripe in the routing plan', function () {
    configureP24();
    config(['payments.providers.stripe' => [
        'enabled' => true,
        'secret_key' => 'sk_test_123',
        'webhook_secret' => 'whsec_test',
    ]]);

    $payment = $this->merchant->payments()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $names = app(PaymentRoutingStrategy::class)->resolveProviders($payment)->providers();

    expect($names)->toContain('stripe')->toContain('p24')->toContain('mock')
        ->and(array_search('stripe', $names))->toBeLessThan(array_search('p24', $names))
        ->and(array_search('p24', $names))->toBeLessThan(array_search('mock', $names));
});

// ---------------------------------------------------------------------------
// Merchant isolation
// ---------------------------------------------------------------------------

it('payments created through api are scoped to the authenticated merchant', function () {
    configureP24();

    $response = $this->postJson('/api/v1/payments', [
        'amount' => 1050,
        'currency' => 'USD',
    ], $this->headers);

    expect($response->status())->toBe(201)
        ->and($response->json('data.currency'))->toBe('USD');
});

// ---------------------------------------------------------------------------
// Regression
// ---------------------------------------------------------------------------

it('does not break existing stripe, payu or mock providers', function () {
    $stripeProvider = app(StripePaymentProvider::class);
    $payuProvider = app(PayUPaymentProvider::class);
    $mockProvider = app(MockPaymentProvider::class);

    expect($stripeProvider->name())->toBe('stripe')
        ->and($payuProvider->name())->toBe('payu')
        ->and($mockProvider->name())->toBe('mock');
});
