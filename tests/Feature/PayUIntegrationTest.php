<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Contracts\Payments\PaymentRoutingStrategy;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $user = User::factory()->create();
    $user->merchants()->attach($this->merchant, ['role' => 'owner']);
    $this->rawKey = (new CreateApiKey)->create($this->merchant, 'test')->rawKey;
    $this->headers = ['Authorization' => 'Bearer '.$this->rawKey];
});

function configurePayUForIntegration(bool $enabled = true, ?string $clientId = 'test_client', ?string $clientSecret = 'test_secret', ?string $posId = 'test_pos'): void
{
    Config::set('payments.providers.payu', [
        'enabled' => $enabled,
        'environment' => 'sandbox',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'merchant_pos_id' => $posId,
        'second_key' => 'test_second_key',
        'continue_url' => 'https://example.com/continue',
        'notify_url' => 'https://example.com/notify',
    ]);
}

it('excludes payu from routing when disabled', function () {
    configurePayUForIntegration(enabled: false);

    $payment = Merchant::factory()->create()->payments()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $plan = app(PaymentRoutingStrategy::class)->resolveProviders($payment);

    expect($plan->providers)->toContain('mock')->not->toContain('payu');
});

it('excludes payu from routing when enabled but missing credentials', function () {
    configurePayUForIntegration(enabled: true, clientId: null);

    $payment = $this->merchant->payments()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $plan = app(PaymentRoutingStrategy::class)->resolveProviders($payment);

    expect($plan->providers)->toContain('mock')->not->toContain('payu');
});

it('includes payu in routing when configured', function () {
    configurePayUForIntegration();

    $payment = $this->merchant->payments()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $plan = app(PaymentRoutingStrategy::class)->resolveProviders($payment);

    expect($plan->providers)->toContain('payu');
});

it('places payu after stripe in the routing order', function () {
    configurePayUForIntegration();
    Config::set('payments.providers.stripe', [
        'enabled' => true,
        'secret_key' => 'sk_test_123',
        'webhook_secret' => 'whsec_test',
    ]);

    $payment = $this->merchant->payments()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $plan = app(PaymentRoutingStrategy::class)->resolveProviders($payment);

    expect($plan->providers)->toContain('payu')->toContain('stripe');

    $payuIndex = array_search('payu', $plan->providers);
    $stripeIndex = array_search('stripe', $plan->providers);

    expect($payuIndex)->toBeGreaterThan($stripeIndex);
});
