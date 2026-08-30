<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Models\Merchant;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant and a real API key, returning the raw key for the
 * Authorization header.
 *
 * @return array{0: Merchant, 1: string}
 */
function retrievalMerchantWithKey(string $name = 'Acme Payments'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

function retrievalList(?string $rawKey = null, array $query = []): TestResponse
{
    $headers = $rawKey !== null ? ['Authorization' => "Bearer {$rawKey}"] : [];

    // test() resolves the currently running test case, which provides
    // getJson() — plain helper functions have no $this binding.
    return test()->getJson('/api/v1/payments?'.http_build_query($query), $headers);
}

function retrievalShow(?string $rawKey, string $reference): TestResponse
{
    $headers = $rawKey !== null ? ['Authorization' => "Bearer {$rawKey}"] : [];

    return test()->getJson("/api/v1/payments/{$reference}", $headers);
}

it('lists payments for the authenticated merchant', function () {
    [$merchant, $rawKey] = retrievalMerchantWithKey();
    $merchant->payments()->createMany([
        ['reference' => 'pay_'.Str::ulid(), 'amount' => 1050, 'currency' => 'USD'],
        ['reference' => 'pay_'.Str::ulid(), 'amount' => 2500, 'currency' => 'EUR'],
    ]);

    retrievalList($rawKey)
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure([
            'data' => [['reference', 'amount', 'currency', 'status', 'description', 'metadata', 'created_at', 'updated_at']],
            'links',
            'meta',
        ]);
});

it('excludes payments belonging to other merchants', function () {
    [$merchantA, $keyA] = retrievalMerchantWithKey('Merchant A');
    $merchantB = Merchant::factory()->create(['name' => 'Merchant B']);

    $paymentA = $merchantA->payments()->create(['reference' => 'pay_'.Str::ulid(), 'amount' => 1000, 'currency' => 'USD']);
    $paymentB = $merchantB->payments()->create(['reference' => 'pay_'.Str::ulid(), 'amount' => 2000, 'currency' => 'USD']);

    $references = retrievalList($keyA)->assertOk()->json('data.*.reference');

    expect($references)->toContain($paymentA->reference)
        ->not->toContain($paymentB->reference)
        ->toHaveCount(1);
});

it('returns an empty list when the merchant has no payments', function () {
    [, $rawKey] = retrievalMerchantWithKey();

    retrievalList($rawKey)
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('orders payments newest first with deterministic secondary ordering', function () {
    [$merchant, $rawKey] = retrievalMerchantWithKey();
    $sameMoment = now();

    $older = $merchant->payments()->create([
        'reference' => 'pay_'.Str::ulid(), 'amount' => 100, 'currency' => 'USD', 'created_at' => $sameMoment->subHour(),
    ]);
    $first = $merchant->payments()->create([
        'reference' => 'pay_'.Str::ulid(), 'amount' => 200, 'currency' => 'USD', 'created_at' => $sameMoment,
    ]);
    $second = $merchant->payments()->create([
        'reference' => 'pay_'.Str::ulid(), 'amount' => 300, 'currency' => 'USD', 'created_at' => $sameMoment,
    ]);

    // Older payment last; identical timestamps fall back to id DESC.
    retrievalList($rawKey)->assertOk()->assertJsonPath('data.*.reference', [
        $second->reference, $first->reference, $older->reference,
    ]);
});

it('paginates with a default of 20 per page', function () {
    [$merchant, $rawKey] = retrievalMerchantWithKey();
    $merchant->payments()->createMany(
        collect(range(1, 25))->map(fn (int $i) => [
            'reference' => 'pay_'.Str::ulid(), 'amount' => $i * 100, 'currency' => 'USD',
        ])->all()
    );

    $response = retrievalList($rawKey)->assertOk();

    expect(count($response->json('data')))->toBe(20)
        ->and($response->json('meta.total'))->toBe(25)
        ->and($response->json('meta.last_page'))->toBe(2);

    // Second page carries the remaining 5 payments.
    retrievalList($rawKey, ['page' => 2])->assertOk()
        ->assertJsonCount(5, 'data');
});

it('honours custom per_page values within bounds', function (int $perPage, int $expected) {
    [$merchant, $rawKey] = retrievalMerchantWithKey();
    $merchant->payments()->createMany(
        collect(range(1, 50))->map(fn (int $i) => [
            'reference' => 'pay_'.Str::ulid(), 'amount' => $i, 'currency' => 'USD',
        ])->all()
    );

    retrievalList($rawKey, ['per_page' => $perPage])->assertOk();

    expect(count(retrievalList($rawKey, ['per_page' => $perPage])->json('data')))->toBe($expected);
})->with([
    'minimum' => [1, 1],
    'custom' => [50, 50],
    'maximum' => [100, 50], // only 50 payments exist
]);

it('rejects invalid per_page values', function (int|string $perPage) {
    [, $rawKey] = retrievalMerchantWithKey();

    retrievalList($rawKey, ['per_page' => $perPage])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
})->with([
    'zero' => 0,
    'negative' => -5,
    'over maximum' => 101,
    'non-integer' => 'abc',
]);

it('requires an API key to list payments', function () {
    retrievalList(null)->assertUnauthorized()->assertJson(['message' => 'Invalid API key.']);
});

it('rejects an invalid API key with the generic error', function () {
    retrievalList(CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

it('retrieves a single payment by its public reference', function () {
    [$merchant, $rawKey] = retrievalMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_'.Str::ulid(),
        'amount' => 1050,
        'currency' => 'USD',
        'description' => 'Test payment',
        'metadata' => ['order_id' => 'ORD-123'],
    ]);

    retrievalShow($rawKey, $payment->reference)
        ->assertOk()
        ->assertJsonPath('data.reference', $payment->reference)
        ->assertJsonPath('data.amount', 1050)
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.metadata.order_id', 'ORD-123');
});

it('returns 404 for an unknown payment reference', function () {
    [, $rawKey] = retrievalMerchantWithKey();

    retrievalShow($rawKey, 'pay_'.Str::ulid())->assertNotFound();
});

it('returns 404 when accessing another merchant payment', function () {
    [, $keyA] = retrievalMerchantWithKey('Merchant A');
    $merchantB = Merchant::factory()->create(['name' => 'Merchant B']);
    $paymentB = $merchantB->payments()->create(['reference' => 'pay_'.Str::ulid(), 'amount' => 9900, 'currency' => 'USD']);

    // 404 — not 403 — so the existence of the payment is never revealed.
    retrievalShow($keyA, $paymentB->reference)->assertNotFound();
});

it('never exposes internal fields in retrieval responses', function () {
    [$merchant, $rawKey] = retrievalMerchantWithKey();
    $payment = $merchant->payments()->create([
        'reference' => 'pay_'.Str::ulid(),
        'amount' => 1000,
        'currency' => 'USD',
        'idempotency_key' => 'secret-order-key',
    ]);

    $listing = retrievalList($rawKey)->assertOk()->json('data.0');
    $single = retrievalShow($rawKey, $payment->reference)->assertOk()->json('data');

    foreach ([$listing, $single] as $payload) {
        expect($payload)->not->toHaveKey('id')
            ->and($payload)->not->toHaveKey('merchant_id')
            ->and($payload)->not->toHaveKey('idempotency_key');
    }
});

it('ignores a merchant_id query parameter when listing', function () {
    [$merchantA, $keyA] = retrievalMerchantWithKey('Merchant A');
    $merchantB = Merchant::factory()->create(['name' => 'Merchant B']);
    $merchantB->payments()->create(['reference' => 'pay_'.Str::ulid(), 'amount' => 7000, 'currency' => 'USD']);
    $paymentA = $merchantA->payments()->create(['reference' => 'pay_'.Str::ulid(), 'amount' => 1000, 'currency' => 'USD']);

    $references = retrievalList($keyA, ['merchant_id' => $merchantB->id])
        ->assertOk()
        ->json('data.*.reference');

    expect($references)->toBe([$paymentA->reference]);
});

it('keeps the payment creation and me endpoints working', function () {
    [$merchant, $rawKey] = retrievalMerchantWithKey();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertOk()
        ->assertJsonPath('merchant.id', $merchant->id);

    $this->postJson('/api/v1/payments', [
        'amount' => 1050,
        'currency' => 'usd',
    ], ['Authorization' => "Bearer {$rawKey}"])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    // The created payment is retrievable through the new endpoint.
    $reference = $this->postJson('/api/v1/payments', [
        'amount' => 2500,
        'currency' => 'EUR',
    ], ['Authorization' => "Bearer {$rawKey}", 'Idempotency-Key' => 'order-1'])
        ->assertCreated()
        ->json('data.reference');

    retrievalShow($rawKey, $reference)->assertOk()->assertJsonPath('data.amount', 2500);
});
