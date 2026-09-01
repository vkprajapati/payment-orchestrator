<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Actions\Payments\CreateIdempotentPayment;
use App\Actions\Payments\CreatePayment;
use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant for payment API tests.
 */
function paymentApiMerchant(string $name = 'Acme Payments'): Merchant
{
    return Merchant::factory()->create(['name' => $name]);
}

/**
 * Create a real API key via the production action and return
 * [Merchant, rawKey] for authenticating requests.
 *
 * @return array{0: Merchant, 1: string}
 */
function paymentApiKey(Merchant $merchant): array
{
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

/**
 * Authorization header for a raw API key.
 *
 * @return array<string, string>
 */
function paymentAuthHeader(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

it('creates a payment with a valid API key', function () {
    [$merchant, $rawKey] = paymentApiKey(paymentApiMerchant());

    $response = $this->postJson('/api/v1/payments', [
        'amount' => 1050,
        'currency' => 'usd',
        'description' => 'Order #123',
        'metadata' => ['order_id' => 'ORD-123'],
    ], paymentAuthHeader($rawKey));

    $response->assertCreated()->assertJsonStructure([
        'data' => ['reference', 'amount', 'currency', 'status', 'description', 'metadata', 'created_at', 'updated_at'],
    ]);

    $payment = Payment::query()->sole();

    expect($payment->merchant_id)->toBe($merchant->id)
        ->and($payment->amount)->toBe(1050)
        ->and($payment->currency)->toBe('USD')
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->description)->toBe('Order #123')
        ->and($payment->metadata)->toBe(['order_id' => 'ORD-123'])
        ->and($payment->reference)->toStartWith('pay_');
});

it('requires an API key', function () {
    $this->postJson('/api/v1/payments', ['amount' => 1050, 'currency' => 'USD'])
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects an invalid API key', function () {
    paymentApiKey(paymentApiMerchant());

    $this->postJson('/api/v1/payments', ['amount' => 1050, 'currency' => 'USD'], paymentAuthHeader('sk_test_'.Str::random(40)))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

it('normalizes the currency to uppercase', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    foreach (['usd', 'eur', 'pln'] as $currency) {
        $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => $currency], paymentAuthHeader($rawKey))
            ->assertCreated()
            ->assertJsonPath('data.currency', strtoupper($currency));
    }
});

it('allows the description to be omitted', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $this->postJson('/api/v1/payments', ['amount' => 2500, 'currency' => 'EUR'], paymentAuthHeader($rawKey))
        ->assertCreated()
        ->assertJsonPath('data.description', null);
});

it('rejects a missing amount', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $this->postJson('/api/v1/payments', ['currency' => 'USD'], paymentAuthHeader($rawKey))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
});

it('rejects non-integer amounts', function (mixed $amount) {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $this->postJson('/api/v1/payments', ['amount' => $amount, 'currency' => 'USD'], paymentAuthHeader($rawKey))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');
})->with([
    'decimal' => 10.50,
    'numeric string' => '1050',
    'zero' => 0,
    'negative' => -100,
]);

it('rejects a missing or invalid currency', function (array $payload) {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $this->postJson('/api/v1/payments', $payload, paymentAuthHeader($rawKey))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('currency');
})->with([
    'missing currency' => [['amount' => 1000]],
    'too short' => [['amount' => 1000, 'currency' => 'US']],
    'too long' => [['amount' => 1000, 'currency' => 'USDD']],
    'not alphabetic' => [['amount' => 1000, 'currency' => 'U1D']],
]);

it('rejects an overly long description', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD', 'description' => str_repeat('a', 256)], paymentAuthHeader($rawKey))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('description');
});

it('rejects non-array metadata', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD', 'metadata' => 'not-an-array'], paymentAuthHeader($rawKey))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('metadata');
});

it('rejects an empty Idempotency-Key header', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], paymentAuthHeader($rawKey) + ['Idempotency-Key' => '   '])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');
});

it('rejects an oversized Idempotency-Key header', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], paymentAuthHeader($rawKey) + ['Idempotency-Key' => str_repeat('k', 256)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('idempotency_key');
});

it('creates separate payments for requests without an idempotency key', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $first = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], paymentAuthHeader($rawKey));
    $second = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], paymentAuthHeader($rawKey));

    $first->assertCreated();
    $second->assertCreated();
    expect($first->json('data.reference'))->not->toBe($second->json('data.reference'))
        ->and(Payment::count())->toBe(2);
});

it('replays the original payment for the same merchant and idempotency key', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());
    $headers = paymentAuthHeader($rawKey) + ['Idempotency-Key' => 'order-123'];
    $payload = ['amount' => 1050, 'currency' => 'USD', 'description' => 'Order #123'];

    $first = $this->postJson('/api/v1/payments', $payload, $headers);
    $second = $this->postJson('/api/v1/payments', $payload, $headers);

    $first->assertCreated()->assertHeader('Idempotent-Replayed', 'false');
    // The retry replays the exact stored response: same 201 status, same
    // body, same payment — the domain operation is never executed twice.
    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json('data.reference'))->toBe($first->json('data.reference'))
        ->and($second->json())->toBe($first->json())
        ->and(Payment::count())->toBe(1);
});

it('isolates idempotency keys per merchant', function () {
    [, $merchantAKey] = paymentApiKey(paymentApiMerchant('Merchant A'));
    [, $merchantBKey] = paymentApiKey(paymentApiMerchant('Merchant B'));

    $first = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], paymentAuthHeader($merchantAKey) + ['Idempotency-Key' => 'shared-key']);
    $second = $this->postJson('/api/v1/payments', ['amount' => 2000, 'currency' => 'EUR'], paymentAuthHeader($merchantBKey) + ['Idempotency-Key' => 'shared-key']);

    $first->assertCreated();
    $second->assertCreated();
    expect($first->json('data.reference'))->not->toBe($second->json('data.reference'))
        ->and(Payment::count())->toBe(2);
});

it('rejects a key reused with a different request payload', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());
    $headers = paymentAuthHeader($rawKey) + ['Idempotency-Key' => 'order-123'];

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], $headers)->assertCreated();

    // The same key with a DIFFERENT logical request must never execute:
    // controlled conflict, no duplicate payment, no hash leakage.
    $second = $this->postJson('/api/v1/payments', ['amount' => 5000, 'currency' => 'USD'], $headers);

    $second->assertStatus(409)
        ->assertJson(['message' => 'Idempotency key has already been used with a different request.']);

    expect(Payment::count())->toBe(1)
        ->and($second->json('data'))->toBeNull();
});

it('never lets the request body override the authenticated merchant', function () {
    [$merchant, $rawKey] = paymentApiKey(paymentApiMerchant());
    $otherMerchant = paymentApiMerchant('Other Merchant');

    $this->postJson('/api/v1/payments', [
        'merchant_id' => $otherMerchant->id,
        'amount' => 1000,
        'currency' => 'USD',
    ], paymentAuthHeader($rawKey))->assertCreated();

    $payment = Payment::query()->sole();

    expect($payment->merchant_id)->toBe($merchant->id)
        ->and($payment->merchant_id)->not->toBe($otherMerchant->id);
});

it('does not expose internal fields in the response', function () {
    [, $rawKey] = paymentApiKey(paymentApiMerchant());

    $data = $this->postJson('/api/v1/payments', [
        'amount' => 1000,
        'currency' => 'USD',
    ], paymentAuthHeader($rawKey) + ['Idempotency-Key' => 'order-42'])->assertCreated()->json('data');

    expect($data)->toHaveKeys(['reference', 'amount', 'currency', 'status', 'description', 'metadata', 'created_at', 'updated_at']);

    foreach (['id', 'merchant_id', 'idempotency_key', 'key_hash'] as $forbidden) {
        expect($data)->not->toHaveKey($forbidden);
    }
});

it('recovers from a unique constraint race between simultaneous idempotent requests', function () {
    // Simulate the race: the first lookup misses (as in a concurrent
    // window), the INSERT then collides with a payment created by the
    // competing request, and the action must replay the winner's payment
    // instead of crashing. In production the composite
    // UNIQUE(merchant_id, idempotency_key) constraint provides this
    // guarantee; true parallelism is impractical under Pest, so the
    // recovery path is exercised directly here.
    $merchant = paymentApiMerchant();
    $action = new class(app(CreatePayment::class)) extends CreateIdempotentPayment
    {
        public int $lookups = 0;

        protected function findExisting(Merchant $merchant, string $idempotencyKey): ?Payment
        {
            // First call (fast path): pretend the key is not present.
            if ($this->lookups++ === 0) {
                return null;
            }

            return $merchant->payments()->where('idempotency_key', $idempotencyKey)->first();
        }
    };

    $payment = Payment::factory()->for($merchant)->create(['idempotency_key' => 'race-key']);

    $result = $action->create($merchant, ['amount' => 1000, 'currency' => 'USD'], 'race-key');

    expect($result->created)->toBeFalse()
        ->and($result->payment->is($payment))->toBeTrue()
        ->and(Payment::count())->toBe(1);
});

it('enforces the composite unique constraint at the database level', function () {
    $merchant = paymentApiMerchant();
    Payment::factory()->for($merchant)->create(['idempotency_key' => 'dup-key']);

    expect(fn () => Payment::factory()->for($merchant)->create(['idempotency_key' => 'dup-key']))
        ->toThrow(QueryException::class)
        ->and(Payment::count())->toBe(1);
});
