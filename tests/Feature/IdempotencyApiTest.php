<?php

use App\Actions\Api\HandleIdempotentRequest;
use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\IdempotencyStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\IdempotencyKey;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant with a real API key, returning the raw key.
 *
 * (idem-prefixed helpers avoid clashing with sibling test files under the
 * same Pest process.)
 *
 * @return array{0: Merchant, 1: string}
 */
function idemMerchant(string $name = 'Idempotency Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

function idemAuth(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

/**
 * A succeeded payment (with a successful mock attempt) owned by the
 * merchant — the precondition for processing and refunds.
 *
 * @return array{0: Payment, 1: PaymentAttempt}
 */
function idemSucceededPayment(Merchant $merchant, int $amount = 10000): array
{
    $payment = Payment::factory()->for($merchant)->create([
        'amount' => $amount,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
    ]);

    $attempt = PaymentAttempt::factory()->forPayment($payment)->succeeded()->create([
        'provider' => 'mock',
        'provider_payment_id' => 'pi_idem_1',
    ]);

    return [$payment, $attempt];
}

// ---------------------------------------------------------------------------
// Payment creation
// ---------------------------------------------------------------------------

it('executes a keyed payment creation normally on first request', function () {
    [$merchant, $rawKey] = idemMerchant();

    $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => 'create-1']);

    $response->assertCreated()->assertHeader('Idempotent-Replayed', 'false');

    $record = IdempotencyKey::query()->where('key', 'create-1')->sole();

    expect($record->merchant_id)->toBe($merchant->id)
        ->and($record->request_method)->toBe('POST')
        ->and($record->request_path)->toBe('/api/v1/payments')
        ->and($record->status->value)->toBe('completed')
        ->and($record->response_status)->toBe(201)
        ->and($record->completed_at)->not->toBeNull();
});

it('replays the identical payment creation retry without re-executing', function () {
    [, $rawKey] = idemMerchant();
    $headers = idemAuth($rawKey) + ['Idempotency-Key' => 'create-retry'];
    $payload = ['amount' => 1050, 'currency' => 'USD', 'metadata' => ['order' => 'ORD-9']];

    $first = $this->postJson('/api/v1/payments', $payload, $headers);
    $second = $this->postJson('/api/v1/payments', $payload, $headers);

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(Payment::count())->toBe(1)
        ->and(IdempotencyKey::count())->toBe(1);
});

it('creates only one payment for repeated identical retries', function () {
    [, $rawKey] = idemMerchant();
    $headers = idemAuth($rawKey) + ['Idempotency-Key' => 'create-multi'];
    $payload = ['amount' => 1000, 'currency' => 'USD'];

    foreach (range(1, 4) as $attempt) {
        $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();
    }

    expect(Payment::count())->toBe(1)
        ->and(IdempotencyKey::count())->toBe(1);
});

it('rejects the same key used with a different payload', function () {
    [, $rawKey] = idemMerchant();
    $headers = idemAuth($rawKey) + ['Idempotency-Key' => 'create-conflict'];

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], $headers)->assertCreated();

    $second = $this->postJson('/api/v1/payments', ['amount' => 2000, 'currency' => 'USD'], $headers);

    $second->assertStatus(409)
        ->assertJson(['message' => 'Idempotency key has already been used with a different request.']);

    expect(Payment::count())->toBe(1);
});

it('keeps the same idempotency key independent per merchant', function () {
    [, $keyA] = idemMerchant('Merchant A');
    [, $keyB] = idemMerchant('Merchant B');
    $sharedKey = 'shared-merchant-key';

    $first = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($keyA) + ['Idempotency-Key' => $sharedKey]);
    $second = $this->postJson('/api/v1/payments', ['amount' => 2000, 'currency' => 'EUR'], idemAuth($keyB) + ['Idempotency-Key' => $sharedKey]);

    $first->assertCreated();
    $second->assertCreated();

    expect($first->json('data.reference'))->not->toBe($second->json('data.reference'))
        ->and(Payment::count())->toBe(2)
        ->and(IdempotencyKey::count())->toBe(2);
});

it('preserves the previous behavior when no idempotency key is sent', function () {
    [, $rawKey] = idemMerchant();
    $payload = ['amount' => 1000, 'currency' => 'USD'];

    $first = $this->postJson('/api/v1/payments', $payload, idemAuth($rawKey));
    $second = $this->postJson('/api/v1/payments', $payload, idemAuth($rawKey));

    $first->assertCreated();
    $second->assertCreated();

    expect($first->json('data.reference'))->not->toBe($second->json('data.reference'))
        ->and(Payment::count())->toBe(2)
        ->and(IdempotencyKey::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Payment processing
// ---------------------------------------------------------------------------

it('processes a payment normally on the first keyed request', function () {
    [$merchant, $rawKey] = idemMerchant();
    $payment = Payment::factory()->for($merchant)->create(['amount' => 1000, 'currency' => 'USD']);

    $response = $this->postJson("/api/v1/payments/{$payment->reference}/process", [], idemAuth($rawKey) + ['Idempotency-Key' => 'process-1']);

    $response->assertOk()
        ->assertJsonPath('data.payment.status', 'succeeded')
        ->assertJsonPath('data.attempt.status', 'succeeded');

    expect($payment->attempts()->count())->toBe(1)
        ->and(IdempotencyKey::query()->where('key', 'process-1')->sole()->response_status)->toBe(200);
});

it('replays a processing retry without creating another attempt', function () {
    [$merchant, $rawKey] = idemMerchant();
    $payment = Payment::factory()->for($merchant)->create(['amount' => 1000, 'currency' => 'USD']);
    $headers = idemAuth($rawKey) + ['Idempotency-Key' => 'process-retry'];

    $first = $this->postJson("/api/v1/payments/{$payment->reference}/process", [], $headers);
    $second = $this->postJson("/api/v1/payments/{$payment->reference}/process", [], $headers);

    $first->assertOk();
    $second->assertOk()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and($payment->attempts()->count())->toBe(1);
});

it('keeps the same key independent across different payment process paths', function () {
    [$merchant, $rawKey] = idemMerchant();
    $paymentA = Payment::factory()->for($merchant)->create(['amount' => 1000, 'currency' => 'USD']);
    $paymentB = Payment::factory()->for($merchant)->create(['amount' => 2000, 'currency' => 'USD']);
    $sharedKey = 'process-shared-key';

    $a = $this->postJson("/api/v1/payments/{$paymentA->reference}/process", [], idemAuth($rawKey) + ['Idempotency-Key' => $sharedKey]);
    $b = $this->postJson("/api/v1/payments/{$paymentB->reference}/process", [], idemAuth($rawKey) + ['Idempotency-Key' => $sharedKey]);

    $a->assertOk();
    $b->assertOk();

    expect($a->json('data.payment.reference'))->toBe($paymentA->reference)
        ->and($b->json('data.payment.reference'))->toBe($paymentB->reference)
        ->and(IdempotencyKey::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Refund creation
// ---------------------------------------------------------------------------

it('creates and executes a refund normally on the first keyed request', function () {
    [$merchant, $rawKey] = idemMerchant();
    [$payment] = idemSucceededPayment($merchant);

    $response = $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 3000], idemAuth($rawKey) + ['Idempotency-Key' => 'refund-1']);

    $response->assertCreated()->assertJsonPath('data.status', RefundStatus::Succeeded->value);

    expect(IdempotencyKey::query()->where('key', 'refund-1')->sole()->response_status)->toBe(201)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('replays a refund retry without creating another refund', function () {
    [$merchant, $rawKey] = idemMerchant();
    [$payment] = idemSucceededPayment($merchant);
    $headers = idemAuth($rawKey) + ['Idempotency-Key' => 'refund-retry'];

    $first = $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 3000], $headers);
    $second = $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 3000], $headers);

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(Refund::count())->toBe(1)
        // The provider executed exactly once: the stored provider refund id
        // from the first execution is unchanged by the replay.
        ->and($second->json('data.provider_refund_id'))->toBe($first->json('data.provider_refund_id'))
        ->and($payment->refresh()->totalSuccessfulRefundAmount())->toBe(3000);
});

it('keeps the same key independent across the payment and refund endpoints', function () {
    [$merchant, $rawKey] = idemMerchant();
    [$payment] = idemSucceededPayment($merchant);
    $sharedKey = 'cross-endpoint-key';

    $created = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => $sharedKey]);
    $refunded = $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000], idemAuth($rawKey) + ['Idempotency-Key' => $sharedKey]);

    $created->assertCreated();
    $refunded->assertCreated();

    expect($created->json('data.reference'))->toStartWith('pay_')
        ->and($refunded->json('data.reference'))->toStartWith('ref_')
        ->and(IdempotencyKey::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Key validation
// ---------------------------------------------------------------------------

it('works normally on mutation endpoints when the header is absent', function () {
    [$merchant, $rawKey] = idemMerchant();
    [$payment] = idemSucceededPayment($merchant);

    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000], idemAuth($rawKey))
        ->assertCreated();

    expect(IdempotencyKey::count())->toBe(0);
});

it('rejects an empty idempotency key with 422', function () {
    [$merchant, $rawKey] = idemMerchant();
    [$payment] = idemSucceededPayment($merchant);

    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000], idemAuth($rawKey) + ['Idempotency-Key' => ''])
        ->assertUnprocessable();

    expect(Refund::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0);
});

it('rejects a whitespace-only idempotency key with 422', function () {
    [$merchant, $rawKey] = idemMerchant();
    [$payment] = idemSucceededPayment($merchant);

    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000], idemAuth($rawKey) + ['Idempotency-Key' => '   '])
        ->assertUnprocessable();

    expect(Refund::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(0);
});

it('rejects an idempotency key longer than 255 characters with 422', function () {
    [$merchant, $rawKey] = idemMerchant();

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => str_repeat('k', 256)])
        ->assertUnprocessable();

    expect(IdempotencyKey::count())->toBe(0);
});

it('accepts an idempotency key of exactly 255 characters', function () {
    [$merchant, $rawKey] = idemMerchant();

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => str_repeat('k', 255)])
        ->assertCreated();

    expect(IdempotencyKey::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Fingerprinting
// ---------------------------------------------------------------------------

it('produces the same fingerprint regardless of JSON key ordering', function () {
    $action = app(HandleIdempotentRequest::class);

    $ordered = $action->fingerprint('POST', '/api/v1/payments', [
        'amount' => 1000,
        'currency' => 'USD',
        'metadata' => ['order_id' => 'ORD-1', 'channel' => 'web'],
    ]);

    $reordered = $action->fingerprint('POST', '/api/v1/payments', [
        'metadata' => ['channel' => 'web', 'order_id' => 'ORD-1'],
        'currency' => 'USD',
        'amount' => 1000,
    ]);

    expect($ordered)->toBe($reordered);
});

it('produces different fingerprints for different payloads', function () {
    $action = app(HandleIdempotentRequest::class);

    $first = $action->fingerprint('POST', '/api/v1/payments', ['amount' => 1000, 'currency' => 'USD']);
    $second = $action->fingerprint('POST', '/api/v1/payments', ['amount' => 2000, 'currency' => 'USD']);

    expect($first)->not->toBe($second);
});

it('rejects the same key with a different payload on the refund endpoint', function () {
    [$merchant, $rawKey] = idemMerchant();
    [$payment] = idemSucceededPayment($merchant);
    $headers = idemAuth($rawKey) + ['Idempotency-Key' => 'refund-conflict'];

    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000], $headers)->assertCreated();

    $second = $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 2000], $headers);

    $second->assertStatus(409)
        ->assertJson(['message' => 'Idempotency key has already been used with a different request.']);

    expect(Refund::count())->toBe(1)
        ->and($payment->refresh()->totalSuccessfulRefundAmount())->toBe(1000);
});

// ---------------------------------------------------------------------------
// Concurrent duplicate requests
// ---------------------------------------------------------------------------

it('rejects a duplicate request while an identical one is in progress', function () {
    [$merchant, $rawKey] = idemMerchant();
    [$payment] = idemSucceededPayment($merchant);

    $path = "/api/v1/payments/{$payment->reference}/refunds";
    $hash = app(HandleIdempotentRequest::class)->fingerprint('POST', $path, ['amount' => 3000]);

    // Simulate a concurrent winner whose reservation is still processing.
    IdempotencyKey::factory()->for($merchant)->create([
        'key' => 'in-flight-key',
        'request_method' => 'POST',
        'request_path' => $path,
        'request_hash' => $hash,
        'status' => IdempotencyStatus::Processing,
    ]);

    $response = $this->postJson($path, ['amount' => 3000], idemAuth($rawKey) + ['Idempotency-Key' => 'in-flight-key']);

    // Controlled conflict — never a second execution, never an infinite wait.
    $response->assertStatus(409)
        ->assertJson(['message' => 'An identical request is already being processed.']);

    expect(Refund::count())->toBe(0)
        ->and(IdempotencyKey::count())->toBe(1);
});

it('never executes the domain operation for an in-flight duplicate', function () {
    [$merchant, $rawKey] = idemMerchant();
    $path = '/api/v1/payments';
    $hash = app(HandleIdempotentRequest::class)->fingerprint('POST', $path, ['amount' => 1000, 'currency' => 'USD', 'idempotency_key' => 'in-flight-pay']);

    IdempotencyKey::factory()->for($merchant)->create([
        'key' => 'in-flight-pay',
        'request_method' => 'POST',
        'request_path' => $path,
        'request_hash' => $hash,
    ]);

    $response = $this->postJson($path, ['amount' => 1000, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => 'in-flight-pay']);

    $response->assertStatus(409);

    expect(Payment::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Failure handling
// ---------------------------------------------------------------------------

it('does not permanently reserve a key for a validation failure', function () {
    [$merchant, $rawKey] = idemMerchant();

    // FormRequest validation fails BEFORE the idempotency layer runs.
    $this->postJson('/api/v1/payments', ['amount' => 0, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => 'corrected-key'])
        ->assertUnprocessable();

    expect(IdempotencyKey::count())->toBe(0);

    // The corrected request using the same key succeeds.
    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => 'corrected-key'])
        ->assertCreated();

    expect(IdempotencyKey::count())->toBe(1)
        ->and(Payment::count())->toBe(1);
});

it('replays a controlled domain failure consistently', function () {
    [$merchant, $rawKey] = idemMerchant();
    [$payment] = idemSucceededPayment($merchant);
    $headers = idemAuth($rawKey) + ['Idempotency-Key' => 'domain-failure'];

    // Over-refund: the request reaches the domain and returns a final 422.
    $first = $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 999999], $headers);
    $second = $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 999999], $headers);

    $first->assertStatus(422);
    $second->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json())
        ->and(Refund::count())->toBe(0)
        ->and(IdempotencyKey::query()->where('key', 'domain-failure')->sole()->response_status)->toBe(422);
});

it('releases the reservation when the operation throws unexpectedly', function () {
    $merchant = Merchant::factory()->create();
    $action = app(HandleIdempotentRequest::class);
    $request = Request::create('/api/v1/payments', 'POST', [], [], [], ['HTTP_IDEMPOTENCY-KEY' => 'crash-key']);

    expect(fn () => $action->wrap($merchant, $request, ['x' => 1], fn (): JsonResponse => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    // The key is NOT stuck in processing — a corrected retry can proceed.
    expect(IdempotencyKey::count())->toBe(0);

    $completed = $action->wrap($merchant, $request, ['x' => 1], fn (): JsonResponse => response()->json(['ok' => true], 201));

    expect($completed->replayed)->toBeFalse()
        ->and($completed->response->status())->toBe(201)
        ->and(IdempotencyKey::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------

it('never stores the raw API key in idempotency records', function () {
    [$merchant, $rawKey] = idemMerchant();

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => 'sec-key'])
        ->assertCreated();

    $record = IdempotencyKey::query()->where('key', 'sec-key')->sole();
    $stored = json_encode($record->attributesToArray());

    expect($stored)->not->toContain($rawKey)
        ->and($stored)->not->toContain('Authorization');
});

it('never exposes idempotency metadata in API responses', function () {
    [, $rawKey] = idemMerchant();

    $first = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => 'sec-meta']);
    $second = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($rawKey) + ['Idempotency-Key' => 'sec-meta']);

    foreach ([$first, $second] as $response) {
        $body = json_encode($response->json());

        expect($body)->not->toContain('request_hash')
            ->and($body)->not->toContain('idempotency_key')
            ->and($body)->not->toContain('locked_at')
            ->and($response->json('data'))->not->toHaveKey('id');
    }
});

it('makes cross-merchant replay impossible', function () {
    [$merchantA, $keyA] = idemMerchant('Merchant A');
    [$merchantB, $keyB] = idemMerchant('Merchant B');
    $headers = ['Idempotency-Key' => 'cross-replay-key'];

    $a = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($keyA) + $headers);
    $b = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], idemAuth($keyB) + $headers);

    $a->assertCreated();
    $b->assertCreated();

    // B never receives A's response: separate merchants, separate records.
    expect($a->json('data.reference'))->not->toBe($b->json('data.reference'))
        ->and(Payment::count())->toBe(2)
        ->and(IdempotencyKey::count())->toBe(2)
        ->and(Payment::query()->where('reference', $b->json('data.reference'))->sole()->merchant_id)->toBe($merchantB->id);
});

// ---------------------------------------------------------------------------
// Database-level protection
// ---------------------------------------------------------------------------

it('enforces the composite unique constraint at the database level', function () {
    $merchant = Merchant::factory()->create();

    IdempotencyKey::factory()->for($merchant)->create([
        'key' => 'dup-key',
        'request_method' => 'POST',
        'request_path' => '/api/v1/payments',
    ]);

    expect(fn () => IdempotencyKey::factory()->for($merchant)->create([
        'key' => 'dup-key',
        'request_method' => 'POST',
        'request_path' => '/api/v1/payments',
    ]))->toThrow(QueryException::class)
        ->and(IdempotencyKey::count())->toBe(1);
});

it('allows the same key with a different request path at the database level', function () {
    $merchant = Merchant::factory()->create();

    IdempotencyKey::factory()->for($merchant)->create([
        'key' => 'multi-path',
        'request_method' => 'POST',
        'request_path' => '/api/v1/payments',
    ]);

    IdempotencyKey::factory()->for($merchant)->create([
        'key' => 'multi-path',
        'request_method' => 'POST',
        'request_path' => '/api/v1/payments/pay_1/refunds',
    ]);

    expect(IdempotencyKey::count())->toBe(2);
});

it('deletes idempotency records when the merchant is deleted', function () {
    $merchant = Merchant::factory()->create();

    IdempotencyKey::factory()->count(3)->for($merchant)->create();
    expect(IdempotencyKey::count())->toBe(3);

    $merchant->delete();

    expect(IdempotencyKey::count())->toBe(0);
});
