<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Contracts\Payments\PaymentRoutingStrategy;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Stripe integration: routing eligibility, explicit provider selection,
 * charge execution through a faked SDK client, and webhook security.
 */
beforeEach(function () {
    $this->merchant = Merchant::factory()->create();
    $user = User::factory()->create();
    $user->merchants()->attach($this->merchant, ['role' => 'owner']);
    $this->rawKey = (new CreateApiKey)->create($this->merchant, 'test')->rawKey;
    $this->headers = ['Authorization' => 'Bearer '.$this->rawKey];
});

function configureStripe(bool $enabled = true, ?string $secretKey = 'sk_test_123', ?string $webhookSecret = 'whsec_test_secret'): void
{
    Config::set('payments.providers.stripe', [
        'enabled' => $enabled,
        'secret_key' => $secretKey,
        'webhook_secret' => $webhookSecret,
    ]);
}

/**
 * Extract provider names from a routing plan regardless of its internal
 * representation (string, enum, or DTO with a name property).
 */
function planProviderNames(object $plan): array
{
    foreach (['providers', 'providerNames', 'names', 'order'] as $property) {
        if (! property_exists($plan, $property)) {
            continue;
        }

        $value = $plan->{$property};

        if (! is_array($value)) {
            continue;
        }

        return array_map(function ($provider) {
            if (is_string($provider)) {
                return $provider;
            }

            if ($provider instanceof BackedEnum) {
                return $provider->value;
            }

            $name = $provider->name ?? $provider->provider ?? null;

            return $name instanceof BackedEnum ? $name->value : $name;
        }, $value);
    }

    return [];
}

function createStripePayment(array $headers): array
{
    $response = test()->postJson('/api/v1/payments', [
        'amount' => 1050,
        'currency' => 'USD',
    ], $headers);

    return $response->json('data');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

it('excludes stripe from routing when disabled', function () {
    configureStripe(enabled: false);

    $payment = Merchant::factory()->create()->payments()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $names = planProviderNames(app(PaymentRoutingStrategy::class)->resolveProviders($payment));

    expect($names)->toContain('mock')->not->toContain('stripe');
});

it('excludes stripe from routing when enabled but missing secret key', function () {
    configureStripe(enabled: true, secretKey: null);

    $payment = $this->merchant->payments()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $names = planProviderNames(app(PaymentRoutingStrategy::class)->resolveProviders($payment));

    expect($names)->toContain('mock')->not->toContain('stripe');
});

it('places configured stripe before mock in the routing plan', function () {
    configureStripe();

    $payment = $this->merchant->payments()->create([
        'reference' => 'pay_'.uniqid(),
        'amount' => 1000,
        'currency' => 'USD',
    ]);

    $names = planProviderNames(app(PaymentRoutingStrategy::class)->resolveProviders($payment));

    expect($names)->toContain('stripe')->toContain('mock')
        ->and(array_search('stripe', $names))->toBeLessThan(array_search('mock', $names));
});
