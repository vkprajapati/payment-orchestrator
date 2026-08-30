<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant and a real API key, returning the raw key for the
 * Authorization header.
 *
 * @return array{0: Merchant, 1: string}
 */
function attemptMerchantWithKey(string $name = 'Attempt Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

function attemptStore(?string $rawKey, string $paymentReference, array $payload = []): TestResponse
{
    $headers = $rawKey !== null ? ['Authorization' => "Bearer {$rawKey}"] : [];

    return test()->postJson("/api/v1/payments/{$paymentReference}/attempts", $payload, $headers);
}

it('creates an attempt with an explicit provider', function () {
    [$merchant, $rawKey] = attemptMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_01TESTATTEMPT0000000001',
        'amount' => 1050,
        'currency' => 'USD',
    ]);

    $response = attemptStore($rawKey, $payment->reference, ['provider' => 'mock']);

    $response->assertCreated()
        ->assertJsonPath('data.payment_reference', $payment->reference)
        ->assertJsonPath('data.provider', 'mock')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', 1050)
        ->assertJsonPath('data.currency', 'USD');

    expect($payment->attempts()->count())->toBe(1);
});

it('creates an attempt with the default provider when none is requested', function () {
    [$merchant, $rawKey] = attemptMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_01TESTATTEMPT0000000002',
        'amount' => 2000,
        'currency' => 'EUR',
    ]);

    $response = attemptStore($rawKey, $payment->reference);

    $response->assertCreated()->assertJsonPath('data.provider', 'mock');

    expect($payment->attempts()->count())->toBe(1)
        ->and($payment->refresh()->status->value)->toBe('pending');
});

it('normalizes provider names in the request', function () {
    [$merchant, $rawKey] = attemptMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_01TESTATTEMPT0000000003',
        'amount' => 500,
        'currency' => 'GBP',
    ]);

    attemptStore($rawKey, $payment->reference, ['provider' => '  MOCK '])
        ->assertCreated()
        ->assertJsonPath('data.provider', 'mock');
});

it('only exposes whitelisted fields in the response', function () {
    [$merchant, $rawKey] = attemptMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_01TESTATTEMPT0000000004',
        'amount' => 750,
        'currency' => 'USD',
    ]);

    $data = attemptStore($rawKey, $payment->reference)->json('data');

    expect(array_keys($data))->toEqualCanonicalizing([
        'payment_reference',
        'provider',
        'provider_payment_id',
        'status',
        'amount',
        'currency',
        'failure_code',
        'failure_message',
        'created_at',
        'started_at',
        'completed_at',
    ])
        ->and($data)->not->toHaveKeys(['id', 'merchant_id', 'payment_id', 'request_metadata', 'response_metadata']);
});

it('allows multiple attempts for one payment', function () {
    [$merchant, $rawKey] = attemptMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_01TESTATTEMPT0000000005',
        'amount' => 1050,
        'currency' => 'USD',
    ]);

    attemptStore($rawKey, $payment->reference, ['provider' => 'mock'])->assertCreated();
    attemptStore($rawKey, $payment->reference, ['provider' => 'mock'])->assertCreated();

    expect($payment->attempts()->count())->toBe(2);
});

it('rejects invalid provider names', function () {
    [$merchant, $rawKey] = attemptMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_01TESTATTEMPT0000000006',
        'amount' => 1050,
        'currency' => 'USD',
    ]);

    attemptStore($rawKey, $payment->reference, ['provider' => 'totally-bogus'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('provider');
});

it('rejects a non-string provider', function () {
    [$merchant, $rawKey] = attemptMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_01TESTATTEMPT0000000007',
        'amount' => 1050,
        'currency' => 'USD',
    ]);

    attemptStore($rawKey, $payment->reference, ['provider' => ['mock']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('provider');
});

it('returns 422 for a registered provider that does not support charge yet', function () {
    [$merchant, $rawKey] = attemptMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_01TESTATTEMPT0000000008',
        'amount' => 1050,
        'currency' => 'USD',
    ]);

    // Stripe is registered but not integrated: the controlled
    // PaymentProviderException rendering must surface this as a client
    // error, never a 500, and no attempt may be created.
    $response = attemptStore($rawKey, $payment->reference, ['provider' => 'stripe']);

    $response->assertStatus(422)
        ->assertJsonPath('error', 'provider_not_available');

    expect($payment->attempts()->count())->toBe(0);
});

it('rejects cross-merchant attempt creation with a 404 and no leak', function () {
    [$merchantA, $rawKeyA] = attemptMerchantWithKey('Merchant A');
    $merchantB = Merchant::factory()->create(['name' => 'Merchant B']);
    $paymentB = $merchantB->payments()->create([
        'reference' => 'pay_01TESTATTEMPTB000000001',
        'amount' => 2500,
        'currency' => 'EUR',
    ]);

    // Merchant A's key must never reach Merchant B's payment. The
    // merchant-scoped lookup returns 404 — identical to an unknown
    // reference — so payment existence is never revealed.
    attemptStore($rawKeyA, $paymentB->reference)
        ->assertNotFound()
        // Exact body: proves no debug trace, ids, or ownership hints
        // leak into the production-shape error response.
        ->assertExactJson(['message' => 'Not found.']);

    expect($paymentB->attempts()->count())->toBe(0);
});

it('rejects unknown payment references with the same generic 404', function () {
    [, $rawKey] = attemptMerchantWithKey();

    attemptStore($rawKey, 'pay_01DOESNOTEXIST00000000000')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);
});

it('requires an API key to create attempts', function () {
    attemptStore(null, 'pay_01ANYPAYMENT000000000000000')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid API key.']);
});

it('rejects invalid API keys with the generic 401', function () {
    attemptStore('sk_test_totallyinvalidkey000000000000000000000', 'pay_01ANYPAYMENT000000000000000')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Invalid API key.']);
});
