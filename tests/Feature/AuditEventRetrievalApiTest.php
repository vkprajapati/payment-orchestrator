<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Models\AuditEvent;
use App\Models\Merchant;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant with a real API key, returning the raw key.
 *
 * (auditRetrieval-prefixed helpers avoid clashing with sibling test files
 * under the same Pest process.)
 *
 * @return array{0: Merchant, 1: string}
 */
function auditRetrievalMerchant(string $name = 'Audit Retrieval Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

function auditRetrievalAuth(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

/**
 * Record an audit event through the production AuditLogger service and
 * return the created model.
 */
function auditRetrievalLog(
    Merchant $merchant,
    AuditEventName $event = AuditEventName::PaymentCreated,
    AuditOutcome $outcome = AuditOutcome::Success,
    array $metadata = [],
): AuditEvent {
    app(AuditLogger::class)->log(
        $merchant,
        $event,
        'POST',
        '/api/v1/payments',
        outcome: $outcome,
        responseStatus: 201,
        paymentReference: 'pay_'.Str::ulid(),
        metadata: $metadata,
    );

    return $merchant->auditEvents()->latest('id')->first();
}

function auditRetrievalList(?string $rawKey = null, array $query = []): TestResponse
{
    $headers = $rawKey !== null ? auditRetrievalAuth($rawKey) : [];

    return test()->getJson('/api/v1/audit-events?'.http_build_query($query), $headers);
}

function auditRetrievalShow(?string $rawKey, string $reference): TestResponse
{
    $headers = $rawKey !== null ? auditRetrievalAuth($rawKey) : [];

    return test()->getJson("/api/v1/audit-events/{$reference}", $headers);
}

/**
 * Expected public fields exposed by AuditEventResource.
 *
 * @return list<string>
 */
function auditEventPublicFields(): array
{
    return [
        'reference',
        'event',
        'outcome',
        'http_method',
        'path',
        'response_status',
        'payment_reference',
        'refund_reference',
        'idempotency_replayed',
        'metadata',
        'performed_at',
        'created_at',
    ];
}

/**
 * Internal fields that must NEVER appear in an audit event response.
 *
 * @return list<string>
 */
function auditEventPrivateFields(): array
{
    return ['id', 'merchant_id', 'payment_id', 'refund_id', 'payment_attempt_id', 'request_hash', 'idempotency_key', 'api_key'];
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

it('requires an API key to list audit events', function () {
    auditRetrievalList(null)->assertUnauthorized()->assertJson(['message' => 'Invalid API key.']);
});

it('rejects an invalid API key with the generic error', function () {
    auditRetrievalList(CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

it('requires an API key to retrieve a single audit event', function () {
    auditRetrievalShow(null, 'evt_does_not_exist')->assertUnauthorized();
});

it('rejects an invalid API key for a single audit event', function () {
    auditRetrievalShow(CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH), 'evt_does_not_exist')
        ->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// List endpoint — basic
// ---------------------------------------------------------------------------

it('returns an empty paginated list for a merchant with no events', function () {
    [, $rawKey] = auditRetrievalMerchant();

    $response = auditRetrievalList($rawKey);

    $response->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0)
        ->assertJsonPath('meta.per_page', 20);
});

it('returns only the authenticated merchant audit events', function () {
    [$merchantA, $keyA] = auditRetrievalMerchant('Merchant A');
    [$merchantB] = auditRetrievalMerchant('Merchant B');

    $eventA = auditRetrievalLog($merchantA);
    auditRetrievalLog($merchantB);
    auditRetrievalLog($merchantB);

    $references = collect(auditRetrievalList($keyA)->json('data'))->pluck('reference')->all();

    expect($references)->toBe([$eventA->reference]);
});

it('orders events newest first with deterministic secondary ordering', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();

    $older = auditRetrievalLog($merchant, metadata: ['amount' => 100]);
    $first = auditRetrievalLog($merchant, metadata: ['amount' => 200]);
    $second = auditRetrievalLog($merchant, metadata: ['amount' => 300]);

    $sameMoment = now();

    $older->update(['created_at' => $sameMoment->copy()->subHour()]);
    $first->update(['created_at' => $sameMoment]);
    $second->update(['created_at' => $sameMoment]);

    // Older event last; identical timestamps fall back to id DESC.
    auditRetrievalList($rawKey)->assertOk()->assertJsonPath('data.*.reference', [
        $second->reference, $first->reference, $older->reference,
    ]);
});

// ---------------------------------------------------------------------------
// List endpoint — pagination
// ---------------------------------------------------------------------------

it('paginates with a default of 20 per page', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();
    collect(range(1, 25))->each(fn () => auditRetrievalLog($merchant));

    $response = auditRetrievalList($rawKey)->assertOk();

    expect(count($response->json('data')))->toBe(20)
        ->and($response->json('meta.total'))->toBe(25)
        ->and($response->json('meta.per_page'))->toBe(20)
        ->and($response->json('meta.last_page'))->toBe(2);

    // Second page carries the remaining 5 events.
    auditRetrievalList($rawKey, ['page' => 2])->assertOk()->assertJsonCount(5, 'data');
});

it('honours custom per_page values within bounds', function (int $perPage, int $expected) {
    [$merchant, $rawKey] = auditRetrievalMerchant();
    collect(range(1, 50))->each(fn () => auditRetrievalLog($merchant));

    $response = auditRetrievalList($rawKey, ['per_page' => $perPage])->assertOk();

    expect(count($response->json('data')))->toBe($expected)
        ->and($response->json('meta.per_page'))->toBe($perPage);
})->with([
    'minimum' => [1, 1],
    'custom' => [50, 50],
    'maximum' => [100, 50], // only 50 events exist
]);

it('rejects invalid per_page values', function (int|string $perPage) {
    [, $rawKey] = auditRetrievalMerchant();

    auditRetrievalList($rawKey, ['per_page' => $perPage])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
})->with([
    'zero' => 0,
    'negative' => -5,
    'over maximum' => 101,
    'non-integer' => 'abc',
]);

// ---------------------------------------------------------------------------
// List endpoint — filtering
// ---------------------------------------------------------------------------

it('filters by event', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();

    auditRetrievalLog($merchant); // payment.created
    auditRetrievalLog($merchant); // payment.created
    auditRetrievalLog($merchant, AuditEventName::RefundCreated);

    auditRetrievalList($rawKey, ['event' => AuditEventName::PaymentCreated->value])
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
});

it('filters by outcome', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();

    auditRetrievalLog($merchant);
    auditRetrievalLog($merchant);
    auditRetrievalLog($merchant, AuditEventName::RefundCreated, AuditOutcome::Failure);

    auditRetrievalList($rawKey, ['outcome' => AuditOutcome::Failure->value])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);
});

it('combines event and outcome filters', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();

    auditRetrievalLog($merchant, AuditEventName::PaymentCreated, AuditOutcome::Success);
    auditRetrievalLog($merchant, AuditEventName::PaymentCreated, AuditOutcome::Failure);
    auditRetrievalLog($merchant, AuditEventName::RefundCreated, AuditOutcome::Success);

    auditRetrievalList($rawKey, [
        'event' => AuditEventName::PaymentCreated->value,
        'outcome' => AuditOutcome::Failure->value,
    ])->assertOk()->assertJsonCount(1, 'data');
});

it('rejects an invalid event filter', function () {
    [, $rawKey] = auditRetrievalMerchant();

    auditRetrievalList($rawKey, ['event' => 'payment.unknown'])->assertUnprocessable();
});

it('rejects an invalid outcome filter', function () {
    [, $rawKey] = auditRetrievalMerchant();

    auditRetrievalList($rawKey, ['outcome' => 'mystery'])->assertUnprocessable();
});

it('filters never cross merchant boundaries', function () {
    [$merchantA, $keyA] = auditRetrievalMerchant('Merchant A');
    [$merchantB] = auditRetrievalMerchant('Merchant B');

    auditRetrievalLog($merchantA, AuditEventName::PaymentCreated);
    auditRetrievalLog($merchantB, AuditEventName::RefundCreated);

    // Merchant A has no refund.created events; B's rows never leak into A's
    // filtered result set.
    auditRetrievalList($keyA, ['event' => AuditEventName::RefundCreated->value])
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 0);
});

it('filters by date boundaries inclusively', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();

    $old = auditRetrievalLog($merchant, metadata: ['amount' => 100]);
    $mid = auditRetrievalLog($merchant, metadata: ['amount' => 200]);
    $new = auditRetrievalLog($merchant, metadata: ['amount' => 300]);

    $old->update(['performed_at' => now()->subDays(2)]);
    $mid->update(['performed_at' => now()->subDay()]);
    $new->update(['performed_at' => now()]);

    $response = auditRetrievalList($rawKey, [
        'from' => $mid->performed_at->toDateTimeString(),
        'to' => $new->performed_at->toDateTimeString(),
    ])->assertOk();

    $amounts = collect($response->json('data'))->pluck('metadata.amount')->sort()->values()->all();

    expect($amounts)->toBe([200, 300]);
});

it('date-only from/to normalize to inclusive day boundaries', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();
    auditRetrievalLog($merchant);

    $today = now()->format('Y-m-d');

    auditRetrievalList($rawKey, ['from' => $today, 'to' => $today])
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1);
});

// ---------------------------------------------------------------------------
// Merchant isolation
// ---------------------------------------------------------------------------

it('merchant A cannot list merchant B audit events', function () {
    [$merchantA, $keyA] = auditRetrievalMerchant('Merchant A');
    [$merchantB] = auditRetrievalMerchant('Merchant B');
    auditRetrievalLog($merchantB);
    auditRetrievalLog($merchantB);

    auditRetrievalList($keyA)->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
});

it('unknown and cross-merchant events return identical generic 404', function () {
    [$merchantA, $keyA] = auditRetrievalMerchant('Merchant A');
    [$merchantB] = auditRetrievalMerchant('Merchant B');
    $eventB = auditRetrievalLog($merchantB);

    $unknown = auditRetrievalShow($keyA, 'evt_does_not_exist');
    $foreign = auditRetrievalShow($keyA, $eventB->reference);

    expect($unknown->status())->toBe(404)
        ->and($unknown->json())->toBe(['message' => 'Not found.'])
        ->and($foreign->status())->toBe(404)
        // Existence of another merchant's event is never revealed.
        ->and($foreign->json())->toBe($unknown->json());
});

it('unknown event reference returns 404', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();

    auditRetrievalShow($rawKey, 'evt_does_not_exist')
        ->assertNotFound()
        ->assertJson(['message' => 'Not found.']);
});

it('merchant_id query parameter cannot bypass merchant isolation', function () {
    [$merchantA, $keyA] = auditRetrievalMerchant('Merchant A');
    [$merchantB] = auditRetrievalMerchant('Merchant B');
    $eventB = auditRetrievalLog($merchantB);

    // Even with merchant_id injected, the authenticated merchant is used.
    auditRetrievalList($keyA, ['merchant_id' => $merchantB->id])
        ->assertOk()
        ->assertJsonCount(0, 'data');

    auditRetrievalShow($keyA, $eventB->reference)->assertNotFound();
});

// ---------------------------------------------------------------------------
// Single event retrieval
// ---------------------------------------------------------------------------

it('merchant can retrieve its own audit event', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();
    $event = auditRetrievalLog($merchant, metadata: ['amount' => 1000, 'currency' => 'USD']);

    auditRetrievalShow($rawKey, $event->reference)
        ->assertOk()
        ->assertJsonPath('data.reference', $event->reference)
        ->assertJsonPath('data.event', AuditEventName::PaymentCreated->value)
        ->assertJsonPath('data.outcome', AuditOutcome::Success->value)
        ->assertJsonPath('data.http_method', 'POST')
        ->assertJsonPath('data.path', '/api/v1/payments')
        ->assertJsonPath('data.response_status', 201)
        ->assertJsonPath('data.idempotency_replayed', false)
        ->assertJsonPath('data.metadata', ['amount' => 1000, 'currency' => 'USD'])
        ->assertJsonStructure(['data' => ['performed_at', 'created_at']]);
});

it('single event metadata is null when none was recorded', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();
    $event = auditRetrievalLog($merchant, metadata: []);

    auditRetrievalShow($rawKey, $event->reference)
        ->assertOk()
        ->assertJsonPath('data.metadata', null);
});

// ---------------------------------------------------------------------------
// Response security
// ---------------------------------------------------------------------------

it('list resource exposes only the public field whitelist', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();
    auditRetrievalLog($merchant);

    $data = auditRetrievalList($rawKey)->json('data.0');

    expect($data)->toHaveKeys(auditEventPublicFields());
});

it('single resource exposes only the public field whitelist', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();
    $event = auditRetrievalLog($merchant);

    $data = auditRetrievalShow($rawKey, $event->reference)->json('data');

    expect($data)->toHaveKeys(auditEventPublicFields());
});

it('responses never expose internal ids, tenants, or unsafe metadata', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();

    // Craft a row whose metadata column contains forbidden secrets to prove
    // the READ-boundary whitelist filters it — this is deliberately not
    // relying on the write-time AuditLogger whitelist alone.
    $event = $merchant->auditEvents()->create([
        'reference' => 'evt_'.Str::ulid(),
        'event' => AuditEventName::PaymentCreated->value,
        'http_method' => 'POST',
        'path' => 'api/v1/payments',
        'response_status' => 201,
        'outcome' => AuditOutcome::Success->value,
        'payment_reference' => 'pay_'.Str::ulid(),
        'metadata' => [
            'amount' => 1050,
            'currency' => 'USD',
            'api_key' => 'sk_live_secret_value',
            'request_body' => 'password=hunter2',
        ],
        'performed_at' => now(),
    ]);

    $listData = auditRetrievalList($rawKey)->json('data.0');
    $singleData = auditRetrievalShow($rawKey, $event->reference)->json('data');

    foreach (auditEventPrivateFields() as $field) {
        expect($listData)->not->toHaveKey($field)
            ->and($singleData)->not->toHaveKey($field);
    }

    // Read-boundary allow-list: only safe metadata keys survive.
    expect($listData['metadata'])->toBe(['amount' => 1050, 'currency' => 'USD'])
        ->and($singleData['metadata'])->toBe(['amount' => 1050, 'currency' => 'USD']);

    $serialized = json_encode($singleData);

    expect($serialized)->not->toContain('sk_live_secret_value')
        ->and($serialized)->not->toContain('hunter2')
        ->and($serialized)->not->toContain('api_key')
        ->and($serialized)->not->toContain('request_body');
});

// ---------------------------------------------------------------------------
// Regression — existing audit logging behavior remains correct
// ---------------------------------------------------------------------------

it('payment creation produces exactly one retrievable audit event', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], auditRetrievalAuth($rawKey))
        ->assertCreated();

    $response = auditRetrievalList($rawKey)->assertOk()->assertJsonCount(1, 'data');

    expect($response->json('data.0.event'))->toBe(AuditEventName::PaymentCreated->value)
        ->and($response->json('data.0.response_status'))->toBe(201)
        ->and($response->json('data.0.payment_reference'))->toStartWith('pay_')
        ->and($response->json('data.0.reference'))->toStartWith('evt_')
        ->and($response->json('data.0.metadata'))->toBe(['amount' => 1000, 'currency' => 'USD']);
});

it('idempotent replay does not duplicate the retrievable audit event', function () {
    [$merchant, $rawKey] = auditRetrievalMerchant();
    $headers = auditRetrievalAuth($rawKey) + ['Idempotency-Key' => 'retrieval-replay-1'];
    $payload = ['amount' => 1000, 'currency' => 'USD'];

    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();
    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();

    auditRetrievalList($rawKey)->assertOk()->assertJsonCount(1, 'data');
});
