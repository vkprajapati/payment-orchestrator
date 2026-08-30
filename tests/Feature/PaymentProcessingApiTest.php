<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\PaymentRoutingStrategy;
use App\Data\Payments\PaymentProviderResult;
use App\Data\Payments\PaymentRoutingPlan;
use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant with a real API key for Authorization headers.
 *
 * @return array{0: Merchant, 1: string}
 */
function processingMerchantWithKey(string $name = 'Processing Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

/**
 * Create a payment for the merchant with a known reference.
 */
function processingPayment(Merchant $merchant, string $reference = 'pay_01PROCESS000000000001'): Payment
{
    return $merchant->payments()->create([
        'reference' => $reference,
        'amount' => 1050,
        'currency' => 'USD',
    ]);
}

/**
 * Register a failing provider under the 'stripe' name and override the
 * routing strategy to the given ordered provider plan.
 *
 * @param  list<string>  $providers
 */
function processingOverrideRoute(array $providers): void
{
    $failing = new class implements PaymentProvider
    {
        public function name(): string
        {
            return 'stripe';
        }

        public function charge(Payment $payment, array $data = []): PaymentProviderResult
        {
            return new PaymentProviderResult(
                success: false,
                provider: 'stripe',
                providerPaymentId: null,
                status: PaymentStatus::Failed->value,
                message: 'Simulated provider decline.',
            );
        }

        public function supports(string $operation): bool
        {
            return $operation === self::OPERATION_CHARGE;
        }
    };

    // "stripe" is a known PaymentProviderName, so CreatePaymentAttempt
    // accepts it; the fake is registered last so the manager resolves it.
    app(PaymentProviderManager::class)->register($failing);

    $strategy = new class($providers) implements PaymentRoutingStrategy
    {
        public function __construct(public array $providers) {}

        public function resolveProviders(Payment $payment): PaymentRoutingPlan
        {
            return new PaymentRoutingPlan(providers: $this->providers);
        }
    };

    app()->instance(PaymentRoutingStrategy::class, $strategy);
}
it('processes a pending payment through the default mock provider', function () {
    [$merchant, $rawKey] = processingMerchantWithKey();
    $payment = processingPayment($merchant);

    $response = $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson("/api/v1/payments/{$payment->reference}/process");

    $response->assertOk()
        ->assertJsonPath('data.payment.status', 'succeeded')
        ->assertJsonPath('data.payment.reference', $payment->reference)
        ->assertJsonPath('data.attempt.provider', 'mock')
        ->assertJsonPath('data.attempt.status', 'succeeded');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->attempts()->count())->toBe(1)
        ->and($payment->attempts()->first()->provider)->toBe('mock')
        ->and($payment->attempts()->first()->status->value)->toBe('succeeded');
});

it('does not expose internal ids, merchant ids or provider metadata', function () {
    [$merchant, $rawKey] = processingMerchantWithKey();
    $payment = processingPayment($merchant);

    $data = $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson("/api/v1/payments/{$payment->reference}/process")
        ->assertOk()
        ->json('data');

    expect($data)->not->toHaveKeys(['merchant_id', 'payment_id'])
        ->and($data['payment'])->not->toHaveKeys(['id', 'merchant_id', 'idempotency_key']);
});

it('fails over to the next provider when the first provider fails', function () {
    [$merchant, $rawKey] = processingMerchantWithKey();
    $payment = processingPayment($merchant, 'pay_01FAILOVER000000000001');

    // First 'stripe' (failing fake) then real 'mock'.
    processingOverrideRoute(['stripe', 'mock']);

    $response = $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson("/api/v1/payments/{$payment->reference}/process");

    $response->assertOk()
        ->assertJsonPath('data.payment.status', 'succeeded');

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->attempts()->count())->toBe(2)
        ->and($payment->attempts()->get()[0]->provider)->toBe('stripe')
        ->and($payment->attempts()->get()[0]->status->value)->toBe('failed')
        ->and($payment->attempts()->get()[1]->provider)->toBe('mock')
        ->and($payment->attempts()->get()[1]->status->value)->toBe('succeeded');
});

it('marks the payment failed when every eligible provider fails', function () {
    [$merchant, $rawKey] = processingMerchantWithKey();
    $payment = processingPayment($merchant, 'pay_01ALLFAIL0000000000001');

    // Only the failing fake provider is routed.
    processingOverrideRoute(['stripe']);

    $response = $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson("/api/v1/payments/{$payment->reference}/process");

    $response->assertOk()
        ->assertJsonPath('data.payment.status', 'failed')
        ->assertJsonPath('data.attempt.provider', 'stripe')
        ->assertJsonPath('data.attempt.status', 'failed');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($payment->attempts()->count())->toBe(1);
});

it('does not create duplicate attempts for a single-provider plan', function () {
    [$merchant, $rawKey] = processingMerchantWithKey();
    $payment = processingPayment($merchant);

    $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson("/api/v1/payments/{$payment->reference}/process")
        ->assertOk();

    expect($payment->attempts()->count())->toBe(1);
});
it('rejects reprocessing a succeeded payment with a controlled 409', function () {
    [$merchant, $rawKey] = processingMerchantWithKey();
    $payment = processingPayment($merchant);

    $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson("/api/v1/payments/{$payment->reference}/process")
        ->assertOk();

    $second = $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson("/api/v1/payments/{$payment->reference}/process");

    $second->assertStatus(409)
        ->assertJsonPath('message', 'Payment pay_01PROCESS000000000001 cannot be processed: already succeeded.')
        ->assertJsonPath('status', 'succeeded')
        // No internal exception details may leak.
        ->assertJsonMissing(['exception', 'file', 'line', 'trace']);

    // Provider still called exactly once — no duplicate charges.
    expect($payment->attempts()->count())->toBe(1);
});

it('rejects reprocessing a cancelled payment', function () {
    [$merchant, $rawKey] = processingMerchantWithKey();
    $payment = processingPayment($merchant, 'pay_01CANCELLED00000000001');
    $payment->status = PaymentStatus::Cancelled;
    $payment->save();

    $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson("/api/v1/payments/{$payment->reference}/process")
        ->assertStatus(409)
        ->assertJsonMissing(['exception', 'file', 'line', 'trace']);
});

it('blocks cross-merchant processing with a generic 404', function () {
    [$merchantA, $rawKeyA] = processingMerchantWithKey('Merchant A');
    $merchantB = Merchant::factory()->create(['name' => 'Merchant B']);
    $paymentB = processingPayment($merchantB, 'pay_01MERCHANTB0000000001');

    $this->withHeaders(['Authorization' => "Bearer {$rawKeyA}"])
        ->postJson("/api/v1/payments/{$paymentB->reference}/process")
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);

    expect($paymentB->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($paymentB->attempts()->count())->toBe(0);
});

it('returns the same generic 404 for unknown payment references', function () {
    [, $rawKey] = processingMerchantWithKey();

    $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson('/api/v1/payments/pay_01DOESNOTEXIST0000000000/process')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);
});

it('requires an API key to process payments', function () {
    $this->postJson('/api/v1/payments/pay_01ANYPAY0000000000000000/process')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid API key.']);
});

it('rejects an invalid API key with the generic 401', function () {
    $this->withHeaders(['Authorization' => 'Bearer sk_test_totallyinvalid0000000000000000000000'])
        ->postJson('/api/v1/payments/pay_01ANYPAY0000000000000000/process')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid API key.']);
});

it('ignores merchant_id provided in the request body', function () {
    [$merchant, $rawKey] = processingMerchantWithKey();
    $otherMerchant = Merchant::factory()->create(['name' => 'Other']);
    $payment = processingPayment($merchant);

    $this->withHeaders(['Authorization' => "Bearer {$rawKey}"])
        ->postJson("/api/v1/payments/{$payment->reference}/process", [
            'merchant_id' => $otherMerchant->id,
        ])
        ->assertOk()
        ->assertJsonPath('data.payment.reference', $payment->reference);

    expect($payment->refresh()->merchant_id)->toBe($merchant->id);
});
