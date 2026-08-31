<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant with a real API key, returning the raw key.
 *
 * (Named refundRetrievalMerchant to avoid clashing with helpers in
 * sibling test files under the same Pest process.)
 *
 * @return array{0: Merchant, 1: string}
 */
function refundRetrievalMerchant(string $name = 'Refund Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

/**
 * A succeeded payment owned by the merchant, with one successful attempt.
 *
 * @return array{0: Payment, 1: PaymentAttempt}
 */
function refundRetrievalPaymentWithAttempt(Merchant $merchant, int $amount = 10000, string $provider = 'mock'): array
{
    $payment = Payment::factory()->for($merchant)->create([
        'amount' => $amount,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
    ]);

    $attempt = PaymentAttempt::factory()->forPayment($payment)->succeeded()->create([
        'provider' => $provider,
        'provider_payment_id' => 'pi_test_123',
    ]);

    return [$payment, $attempt];
}

/**
 * Issue an authenticated GET to the refund list endpoint.
 */
function getRefunds(string $reference, string $rawKey, array $query = []): TestResponse
{
    return test()->getJson(
        "/api/v1/payments/{$reference}/refunds".($query !== [] ? '?'.http_build_query($query) : ''),
        ['Authorization' => "Bearer {$rawKey}"],
    );
}

/**
 * Issue an authenticated GET to the single-refund endpoint.
 */
function getSingleRefund(string $paymentRef, string $refundRef, string $rawKey): TestResponse
{
    return test()->getJson(
        "/api/v1/payments/{$paymentRef}/refunds/{$refundRef}",
        ['Authorization' => "Bearer {$rawKey}"],
    );
}

/**
 * Expected public fields exposed by RefundResource.
 */
function refundPublicFields(): array
{
    return [
        'reference',
        'payment_reference',
        'provider',
        'provider_refund_id',
        'amount',
        'currency',
        'status',
        'reason',
        'failure_code',
        'failure_message',
        'requested_at',
        'completed_at',
        'created_at',
        'updated_at',
    ];
}

/**
 * Fields that must NEVER appear in a refund resource response.
 */
function refundPrivateFields(): array
{
    return ['id', 'merchant_id', 'payment_id', 'payment_attempt_id', 'request_metadata', 'response_metadata'];
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

it('list refunds without API key returns 401', function () {
    [$merchant] = refundRetrievalMerchant();
    $payment = Payment::factory()->for($merchant)->create();

    test()->getJson("/api/v1/payments/{$payment->reference}/refunds")
        ->assertUnauthorized();
});

it('list refunds with invalid API key returns 401', function () {
    [$merchant] = refundRetrievalMerchant();
    $payment = Payment::factory()->for($merchant)->create();

    getRefunds($payment->reference, 'invalid-key-value')->assertUnauthorized();
});

it('retrieve a refund without API key returns 401', function () {
    [$merchant] = refundRetrievalMerchant();
    $payment = Payment::factory()->for($merchant)->create();
    $refund = Refund::factory()->forPayment($payment)->create();

    test()->getJson("/api/v1/payments/{$payment->reference}/refunds/{$refund->reference}")
        ->assertUnauthorized();
});

it('retrieve a refund with invalid API key returns 401', function () {
    [$merchant] = refundRetrievalMerchant();
    $payment = Payment::factory()->for($merchant)->create();
    $refund = Refund::factory()->forPayment($payment)->create();

    getSingleRefund($payment->reference, $refund->reference, 'invalid-key-value')->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// List endpoint — basic
// ---------------------------------------------------------------------------

it('authenticated merchant can list refunds for own payment', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);
    Refund::factory()->forPayment($payment)->count(3)->create();

    $response = getRefunds($payment->reference, $rawKey);

    $response->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.total', 3);
});

it('empty refund list returns successful paginated response', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    $response = getRefunds($payment->reference, $rawKey);

    $response->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.per_page', 20);
});

it('only refunds belonging to the requested payment are returned', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment1] = refundRetrievalPaymentWithAttempt($merchant);
    [$payment2] = refundRetrievalPaymentWithAttempt($merchant);

    Refund::factory()->forPayment($payment1)->count(2)->create();
    Refund::factory()->forPayment($payment2)->count(3)->create();

    getRefunds($payment1->reference, $rawKey)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
});

it('refunds from another payment are excluded', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment1] = refundRetrievalPaymentWithAttempt($merchant);
    [$payment2] = refundRetrievalPaymentWithAttempt($merchant);

    Refund::factory()->forPayment($payment1)->create(['amount' => 1000]);
    Refund::factory()->forPayment($payment2)->create(['amount' => 2000]);

    $references = collect(getRefunds($payment1->reference, $rawKey)->json('data'))
        ->pluck('amount')
        ->all();

    expect($references)->toBe([1000]);
});

it('refunds are ordered newest-first', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    $oldest = Refund::factory()->forPayment($payment)->create([
        'created_at' => now()->subMinutes(3),
    ]);
    $middle = Refund::factory()->forPayment($payment)->create([
        'created_at' => now()->subMinutes(2),
    ]);
    $newest = Refund::factory()->forPayment($payment)->create([
        'created_at' => now()->subMinute(),
    ]);

    $refs = collect(getRefunds($payment->reference, $rawKey)->json('data'))
        ->pluck('reference')
        ->all();

    expect($refs)->toBe([$newest->reference, $middle->reference, $oldest->reference]);
});

it('deterministic secondary ordering works when created_at timestamps match', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    $ts = now();
    $r1 = Refund::factory()->forPayment($payment)->create(['created_at' => $ts]);
    $r2 = Refund::factory()->forPayment($payment)->create(['created_at' => $ts]);
    $r3 = Refund::factory()->forPayment($payment)->create(['created_at' => $ts]);

    $refs = collect(getRefunds($payment->reference, $rawKey)->json('data'))
        ->pluck('reference')
        ->all();

    // Same created_at → secondary id DESC keeps newest first.
    expect($refs)->toBe([$r3->reference, $r2->reference, $r1->reference]);
});

// ---------------------------------------------------------------------------
// List endpoint — pagination
// ---------------------------------------------------------------------------

it('default pagination uses 20 per page', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);
    Refund::factory()->forPayment($payment)->count(25)->create();

    $response = getRefunds($payment->reference, $rawKey);

    $response->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta.per_page', 20)
        ->assertJsonPath('meta.total', 25)
        ->assertJsonPath('meta.last_page', 2);
});

it('custom valid per_page works', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);
    Refund::factory()->forPayment($payment)->count(8)->create();

    $response = getRefunds($payment->reference, $rawKey, ['per_page' => 5]);

    $response->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.per_page', 5)
        ->assertJsonPath('meta.total', 8)
        ->assertJsonPath('meta.last_page', 2);
});

it('per_page of 1 works', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);
    Refund::factory()->forPayment($payment)->count(3)->create();

    getRefunds($payment->reference, $rawKey, ['per_page' => 1])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.per_page', 1);
});

it('per_page of 100 works', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);
    Refund::factory()->forPayment($payment)->count(5)->create();

    getRefunds($payment->reference, $rawKey, ['per_page' => 100])
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.per_page', 100);
});

it('per_page of 101 returns 422', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    getRefunds($payment->reference, $rawKey, ['per_page' => 101])
        ->assertStatus(422);
});

it('per_page of 0 returns 422', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    getRefunds($payment->reference, $rawKey, ['per_page' => 0])
        ->assertStatus(422);
});

it('non-integer per_page returns 422', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    getRefunds($payment->reference, $rawKey, ['per_page' => 'abc'])
        ->assertStatus(422);
});

// ---------------------------------------------------------------------------
// List endpoint — filtering
// ---------------------------------------------------------------------------

it('valid status filter works', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    Refund::factory()->forPayment($payment)->create(['status' => RefundStatus::Succeeded]);
    Refund::factory()->forPayment($payment)->create(['status' => RefundStatus::Succeeded]);
    Refund::factory()->forPayment($payment)->create(['status' => RefundStatus::Failed]);
    Refund::factory()->forPayment($payment)->create(['status' => RefundStatus::Pending]);

    getRefunds($payment->reference, $rawKey, ['status' => RefundStatus::Succeeded->value])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
});

it('valid provider filter works', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    Refund::factory()->forPayment($payment)->create(['provider' => 'mock']);
    Refund::factory()->forPayment($payment)->create(['provider' => 'mock']);
    Refund::factory()->forPayment($payment)->create(['provider' => 'stripe']);

    getRefunds($payment->reference, $rawKey, ['provider' => 'mock'])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
});

it('invalid status filter returns 422', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    getRefunds($payment->reference, $rawKey, ['status' => 'unknown_status'])
        ->assertStatus(422);
});

it('invalid provider filter returns 422', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    getRefunds($payment->reference, $rawKey, ['provider' => 'invalid-provider'])
        ->assertStatus(422);
});

it('combined status and provider filters work correctly', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    Refund::factory()->forPayment($payment)->create(['status' => RefundStatus::Succeeded, 'provider' => 'mock']);
    Refund::factory()->forPayment($payment)->create(['status' => RefundStatus::Failed, 'provider' => 'mock']);
    Refund::factory()->forPayment($payment)->create(['status' => RefundStatus::Succeeded, 'provider' => 'stripe']);

    getRefunds($payment->reference, $rawKey, [
        'status' => RefundStatus::Succeeded->value,
        'provider' => 'mock',
    ])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);
});

// ---------------------------------------------------------------------------
// Merchant isolation
// ---------------------------------------------------------------------------

it('merchant cannot list refunds for another merchant\'s payment — returns 404', function () {
    [$merchantA, $keyA] = refundRetrievalMerchant('Merchant A');
    [$merchantB] = refundRetrievalMerchant('Merchant B');
    [$paymentB] = refundRetrievalPaymentWithAttempt($merchantB);
    Refund::factory()->forPayment($paymentB)->count(2)->create();

    getRefunds($paymentB->reference, $keyA)->assertNotFound();
});

it('unknown payment returns identical generic 404 for list endpoint', function () {
    [$merchantA, $keyA] = refundRetrievalMerchant('Merchant A');
    [$merchantB, $keyB] = refundRetrievalMerchant('Merchant B');
    [$paymentB] = refundRetrievalPaymentWithAttempt($merchantB);
    Refund::factory()->forPayment($paymentB)->count(2)->create();

    $unknown = getRefunds('pay_does_not_exist', $keyA);
    $foreign = getRefunds($paymentB->reference, $keyA);

    expect($unknown->status())->toBe(404)
        ->and($unknown->json())->toBe(['message' => 'Not found.'])
        ->and($foreign->json())->toBe($unknown->json());
});

it('merchant_id query parameter cannot bypass merchant isolation', function () {
    [$merchantA, $keyA] = refundRetrievalMerchant('Merchant A');
    [$merchantB] = refundRetrievalMerchant('Merchant B');
    [$paymentB] = refundRetrievalPaymentWithAttempt($merchantB);
    Refund::factory()->forPayment($paymentB)->count(3)->create();

    // Even with merchant_id injected, the authenticated merchant is used.
    getRefunds($paymentB->reference, $keyA, ['merchant_id' => $merchantB->id])
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Single refund retrieval
// ---------------------------------------------------------------------------

it('merchant can retrieve own refund', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);
    $refund = Refund::factory()->forPayment($payment)->create([
        'reason' => 'Customer request',
        'amount' => 3000,
    ]);

    getSingleRefund($payment->reference, $refund->reference, $rawKey)
        ->assertOk()
        ->assertJsonPath('data.reference', $refund->reference)
        ->assertJsonPath('data.amount', 3000)
        ->assertJsonPath('data.reason', 'Customer request')
        ->assertJsonPath('data.payment_reference', $payment->reference)
        ->assertJsonPath('data.status', RefundStatus::Pending->value);
});

it('unknown refund returns 404', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    getSingleRefund($payment->reference, 'ref_does_not_exist', $rawKey)
        ->assertNotFound()
        ->assertJson(['message' => 'Not found.']);
});

it('cross-merchant refund access returns generic 404', function () {
    [$merchantA, $keyA] = refundRetrievalMerchant('Merchant A');
    [$merchantB] = refundRetrievalMerchant('Merchant B');
    [$paymentB] = refundRetrievalPaymentWithAttempt($merchantB);
    $refund = Refund::factory()->forPayment($paymentB)->create();

    getSingleRefund($paymentB->reference, $refund->reference, $keyA)
        ->assertNotFound()
        ->assertJson(['message' => 'Not found.']);
});

it('refund belonging to another payment cannot be accessed through the wrong payment', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment1] = refundRetrievalPaymentWithAttempt($merchant);
    [$payment2] = refundRetrievalPaymentWithAttempt($merchant);
    $refund = Refund::factory()->forPayment($payment1)->create();

    getSingleRefund($payment2->reference, $refund->reference, $rawKey)
        ->assertNotFound()
        ->assertJson(['message' => 'Not found.']);
});

it('single refund retrieval with unknown payment returns 404', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();

    getSingleRefund('pay_does_not_exist', 'ref_also_does_not_exist', $rawKey)
        ->assertNotFound()
        ->assertJson(['message' => 'Not found.']);
});

// ---------------------------------------------------------------------------
// Response security
// ---------------------------------------------------------------------------

it('list resource contains expected public fields', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);
    Refund::factory()->forPayment($payment)->create();

    $data = getRefunds($payment->reference, $rawKey)->json('data.0');

    expect($data)->toHaveKeys(refundPublicFields());
});

it('single resource contains expected public fields', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);
    $refund = Refund::factory()->forPayment($payment)->create();

    $data = getSingleRefund($payment->reference, $refund->reference, $rawKey)->json('data');

    expect($data)->toHaveKeys(refundPublicFields());
});

it('responses never expose internal fields', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);
    $refund = Refund::factory()->forPayment($payment)->create();

    $refund->update([
        'request_metadata' => ['secret' => 'sk_test_hidden'],
        'response_metadata' => ['token' => 'secret_token'],
    ]);

    $listData = getRefunds($payment->reference, $rawKey)->json('data.0');
    $singleData = getSingleRefund($payment->reference, $refund->reference, $rawKey)->json('data');

    foreach (refundPrivateFields() as $field) {
        expect($listData)->not->toHaveKey($field)
            ->and($singleData)->not->toHaveKey($field);
    }

    expect(json_encode($listData))->not->toContain('sk_test_hidden')
        ->and(json_encode($singleData))->not->toContain('secret_token');
});

// ---------------------------------------------------------------------------
// Regression — existing refund creation/execution still works
// ---------------------------------------------------------------------------

it('existing refund creation endpoint still works', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    test()->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 3000], [
        'Authorization' => "Bearer {$rawKey}",
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', RefundStatus::Succeeded->value);
});

// ---------------------------------------------------------------------------
// Regression — existing payment endpoints still work
// ---------------------------------------------------------------------------

it('existing payment retrieval endpoint still works', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    [$payment] = refundRetrievalPaymentWithAttempt($merchant);

    test()->getJson("/api/v1/payments/{$payment->reference}", [
        'Authorization' => "Bearer {$rawKey}",
    ])
        ->assertOk()
        ->assertJsonPath('data.reference', $payment->reference);
});

it('existing payment list endpoint still works', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    Payment::factory()->for($merchant)->count(2)->create();

    test()->getJson('/api/v1/payments', [
        'Authorization' => "Bearer {$rawKey}",
    ])
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('existing payment processing endpoint still works', function () {
    [$merchant, $rawKey] = refundRetrievalMerchant();
    $payment = Payment::factory()->for($merchant)->create();

    test()->postJson("/api/v1/payments/{$payment->reference}/process", [], [
        'Authorization' => "Bearer {$rawKey}",
    ])
        ->assertOk()
        ->assertJsonPath('data.payment.status', 'succeeded')
        ->assertJsonPath('data.payment.reference', $payment->reference)
        ->assertJsonPath('data.attempt.provider', 'mock');

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});
