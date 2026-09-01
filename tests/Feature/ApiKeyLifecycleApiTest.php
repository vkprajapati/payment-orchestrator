<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * @return array{0: Merchant, 1: string}
 */
function apiKeysMerchant(string $name = 'Lifecycle Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);

    return [$merchant, app(CreateApiKey::class)->create($merchant, 'CI/CD')->rawKey];
}

function apiKeysAuth(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

/**
 * Call a lifecycle endpoint.
 */
function apiKeysCall(string $method, string $path, array $query = [], ?string $rawKey = null, array $body = []): TestResponse
{
    $headers = $rawKey !== null ? apiKeysAuth($rawKey) : [];

    return test()->json($method, '/api/v1/api-keys'.$path.'?'.http_build_query($query), $body, $headers);
}

// ---------------------------------------------------------------------------
// Authentication
// ---------------------------------------------------------------------------

it('requires authentication for all lifecycle endpoints', function () {
    apiKeysCall('GET', '')->assertUnauthorized();
    apiKeysCall('GET', '/key_does_not_matter')->assertUnauthorized();
    apiKeysCall('POST', '', [], null)->assertUnauthorized();
    apiKeysCall('POST', '/key_does_not_matter/revoke', [], null)->assertUnauthorized();
});

// ---------------------------------------------------------------------------
// Creation
// ---------------------------------------------------------------------------

it('creates a key and returns the raw key exactly once in the 201 response', function () {
    [, $rawKey] = apiKeysMerchant();

    $response = apiKeysCall('POST', '', [], $rawKey, ['name' => 'Production Server']);

    $response->assertCreated();

    $raw = $response->json('data.raw_key');

    expect($raw)->not->toBeNull()
        ->and($raw)->toStartWith('sk_test_')
        ->and(strlen($raw))->toBe(48);

    test()->getJson('/api/v1/me', apiKeysAuth($raw))->assertOk();
});

it('never stores the plaintext raw key in the database', function () {
    [, $rawKey] = apiKeysMerchant();

    $response = apiKeysCall('POST', '', [], $rawKey, ['name' => 'Secret Key']);
    $reference = $response->json('data.reference');

    $record = ApiKey::where('reference', $reference)->firstOrFail();

    expect($record->key_hash)->not->toBeEmpty()
        ->and($record->key_hash)->not->toContain('sk_test_')
        ->and(DB::table('api_keys')->where('key_hash', $rawKey)->exists())->toBeFalse();
});

it('persists the public reference on the key', function () {
    [, $rawKey] = apiKeysMerchant();

    $reference = apiKeysCall('POST', '', [], $rawKey, ['name' => 'Reference Key'])
        ->assertCreated()
        ->json('data.reference');

    expect($reference)->toStartWith('key_')
        ->and(ApiKey::where('reference', $reference)->exists())->toBeTrue();
});

it('rejects merchant_id from request input — key belongs to the authenticated merchant', function () {
    [$merchantA, $keyA] = apiKeysMerchant('Merchant A');
    [$merchantB] = apiKeysMerchant('Merchant B');

    $response = apiKeysCall('POST', '', [], $keyA, [
        'name' => 'Isolation Key',
        'merchant_id' => $merchantB->id,
    ]);

    $reference = $response->json('data.reference');

    expect($merchantA->apiKeys()->where('reference', $reference)->exists())->toBeTrue()
        ->and($merchantB->apiKeys()->where('reference', $reference)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// List
// ---------------------------------------------------------------------------

it('lists only the authenticated merchant keys without secrets', function () {
    [$merchantA, $keyA] = apiKeysMerchant('Merchant A');
    [, $keyB] = apiKeysMerchant('Merchant B');

    // Merchant A has exactly 2 keys: the CI/CD bootstrap key and "First Key".
    apiKeysCall('POST', '', [], $keyA, ['name' => 'First Key']);

    $data = apiKeysCall('GET', '', [], $keyA)->assertOk()->json('data');

    expect(count($data))->toBe(2);

    $json = json_encode($data);

    foreach ([$keyA, $keyB] as $secret) {
        $secretRef = substr($secret, 0, CreateApiKey::STORED_PREFIX_LENGTH);
        expect($json)->not->toContain($secret)
            ->and($json)->not->toContain($secretRef);
    }
});

// ---------------------------------------------------------------------------
// Show
// ---------------------------------------------------------------------------

it('retrieves a key by reference with safe metadata only', function () {
    [$merchant, $rawKey] = apiKeysMerchant();
    $created = apiKeysCall('POST', '', [], $rawKey, ['name' => 'Show Key', 'label' => 'prod']);

    $reference = $created->json('data.reference');

    $response = apiKeysCall('GET', '/'.$reference, [], $rawKey);

    $response->assertOk();

    $json = json_encode($response->json());

    expect($json)->toContain($reference)
        ->and($json)->toContain('Show Key')
        ->and($json)->toContain('prod')
        ->and($json)->not->toContain($rawKey)
        ->and($json)->not->toContain('key_hash')
        ->and($json)->not->toContain('key_prefix');

    // Structural whitelist: no internal ids (the root is {"data": {...}}).
    $payload = $response->json('data');
    expect($payload)->not->toHaveKey('id')
        ->and($payload)->not->toHaveKey('merchant_id');
});

it('returns identical 404 for unknown and cross-merchant references', function () {
    [$merchantA, $keyA] = apiKeysMerchant('Merchant A');
    [, $keyB] = apiKeysMerchant('Merchant B');

    $bReference = apiKeysCall('POST', '', [], $keyB, ['name' => 'B Key'])->json('data.reference');

    $unknownResponse = apiKeysCall('GET', '/key_does_not_exist', [], $keyA);
    $crossResponse = apiKeysCall('GET', '/'.$bReference, [], $keyA);

    $unknownResponse->assertNotFound();
    $crossResponse->assertNotFound();

    expect($unknownResponse->json())->toBe($crossResponse->json())
        ->and($unknownResponse->json())->toBe(['message' => 'Not found.']);
});

// ---------------------------------------------------------------------------
// Revocation
// ---------------------------------------------------------------------------

it('revokes a key immediately so it can no longer authenticate', function () {
    [$merchant, $rawKey] = apiKeysMerchant();

    $created = apiKeysCall('POST', '', [], $rawKey, ['name' => 'To Revoke']);
    $reference = $created->json('data.reference');
    $newRawKey = $created->json('data.raw_key');

    // The new key authenticates before revocation..
    test()->getJson('/api/v1/me', apiKeysAuth($newRawKey))->assertOk();

    apiKeysCall('POST', '/'.$reference.'/revoke', [], $rawKey)->assertOk();

    // The revoked key now fails generic-auth; the revoking (CI/CD) key still works.

    test()->getJson('/api/v1/me', apiKeysAuth($newRawKey))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);

    test()->getJson('/api/v1/me', apiKeysAuth($rawKey))->assertOk();
});

it('is idempotent when revoking an already revoked key', function () {
    [, $rawKey] = apiKeysMerchant();

    $reference = apiKeysCall('POST', '', [], $rawKey, ['name' => 'Repeat Revoke'])->json('data.reference');

    apiKeysCall('POST', '/'.$reference.'/revoke', [], $rawKey)->assertOk();
    $firstRevokedAt = ApiKey::where('reference', $reference)->value('revoked_at');

    apiKeysCall('POST', '/'.$reference.'/revoke', [], $rawKey)->assertOk();
    $secondRevokedAt = ApiKey::where('reference', $reference)->value('revoked_at');

    expect($firstRevokedAt)->not->toBeNull()
        ->and($firstRevokedAt)->toEqual($secondRevokedAt);
});

it('prevents revoking the currently authenticated key', function () {
    [, $rawKey] = apiKeysMerchant();

    $created = apiKeysCall('POST', '', [], $rawKey, ['name' => 'Self Revoke Attempt']);
    $reference = $created->json('data.reference');
    $newRawKey = $created->json('data.raw_key');

    // Authenticate WITH the newly created key — revoking your own active
    // key must fail safely with 403 to avoid a mid-request auth failure.

    apiKeysCall('POST', '/'.$reference.'/revoke', [], $newRawKey)
        ->assertStatus(403);

    expect(ApiKey::where('reference', $reference)->value('revoked_at'))->toBeNull();
});

it('Merchant A cannot list, show, or revoke Merchant B keys', function () {
    [$merchantA, $keyA] = apiKeysMerchant('Merchant A');
    [, $keyB] = apiKeysMerchant('Merchant B');

    $bReference = apiKeysCall('POST', '', [], $keyB, ['name' => 'B Key'])->json('data.reference');

    $list = apiKeysCall('GET', '', [], $keyA)->json('data');
    expect(collect($list)->pluck('reference'))->not->toContain($bReference);

    apiKeysCall('GET', '/'.$bReference, [], $keyA)->assertNotFound();
    apiKeysCall('POST', '/'.$bReference.'/revoke', [], $keyA)->assertNotFound();

    test()->getJson('/api/v1/me', apiKeysAuth($keyB))->assertOk();
});

it('accepts a future expiration date and rejects past dates', function () {
    [, $rawKey] = apiKeysMerchant();

    apiKeysCall('POST', '', [], $rawKey, [
        'name' => 'Future Expiry',
        'expires_at' => now()->addDays(30)->toDateString(),
    ])->assertCreated();

    apiKeysCall('POST', '', [], $rawKey, [
        'name' => 'Past Expiry',
        'expires_at' => now()->subDay()->toDateString(),
    ])->assertUnprocessable();
});

it('expired keys fail authentication generically', function () {
    [, $rawKey] = apiKeysMerchant();

    $created = apiKeysCall('POST', '', [], $rawKey, [
        'name' => 'Soon Expired',
        'expires_at' => now()->addDay()->toDateString(),
    ]);
    $reference = $created->json('data.reference');
    $newRawKey = $created->json('data.raw_key');

    // The key works before expiration.
    test()->getJson('/api/v1/me', apiKeysAuth($newRawKey))->assertOk();

    // Expire the key.

    ApiKey::where('reference', $reference)->update(['expires_at' => now()->subMinute()]);

    test()->getJson('/api/v1/me', apiKeysAuth($newRawKey))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

// ---------------------------------------------------------------------------
// Response security
// ---------------------------------------------------------------------------

it('never exposes internal identifiers or secrets in any key response', function () {
    [$merchant, $rawKey] = apiKeysMerchant();

    $created = apiKeysCall('POST', '', [], $rawKey, ['name' => 'Secure Key']);
    $reference = $created->json('data.reference');

    $createPayload = $created->json('data');
    expect($createPayload)->not->toHaveKey('id')
        ->and($createPayload)->not->toHaveKey('merchant_id')
        ->and($createPayload)->not->toHaveKey('key_hash')
        ->and($createPayload)->not->toHaveKey('key_prefix')
        ->and(json_encode($createPayload))->not->toContain($rawKey);

    $listData = apiKeysCall('GET', '', [], $rawKey)->assertOk()->json('data');
    $listJson = json_encode($listData);
    expect($listJson)->not->toContain($rawKey);

    foreach ($listData as $item) {
        expect($item)->not->toHaveKey('id')
            ->and($item)->not->toHaveKey('merchant_id')
            ->and($item)->not->toHaveKey('key_hash')
            ->and($item)->not->toHaveKey('raw_key');
    }

    $showPayload = apiKeysCall('GET', '/'.$reference, [], $rawKey)->assertOk()->json();
    $showJson = json_encode($showPayload);
    expect($showPayload)->not->toHaveKey('id')
        ->and($showPayload)->not->toHaveKey('merchant_id')
        ->and($showPayload)->not->toHaveKey('key_hash')
        ->and($showPayload)->not->toHaveKey('key_prefix')
        ->and($showJson)->not->toContain($rawKey);
});

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

it('uses the sensitive bucket for create and revoke, standard for reads', function () {
    config(['rate_limiting.buckets.sensitive.max_attempts' => 1]);
    [, $rawKey] = apiKeysMerchant();

    apiKeysCall('POST', '', [], $rawKey, ['name' => 'Key 1'])->assertCreated();
    apiKeysCall('POST', '', [], $rawKey, ['name' => 'Key 2'])->assertStatus(429);

    test()->getJson('/api/v1/api-keys', apiKeysAuth($rawKey))->assertOk();
});

it('uses standard bucket for key reads, not sensitive', function () {
    config(['rate_limiting.buckets.standard.max_attempts' => 1]);
    [, $rawKey] = apiKeysMerchant();

    test()->getJson('/api/v1/api-keys', apiKeysAuth($rawKey))->assertOk();
    test()->getJson('/api/v1/api-keys', apiKeysAuth($rawKey))->assertStatus(429);
});
// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('idempotent creation does not create a second key or duplicate audit', function () {
    [, $rawKey] = apiKeysMerchant();

    $payload = ['name' => 'Idempotent Key'];
    $headers = apiKeysAuth($rawKey) + ['Idempotency-Key' => 'api-key-idempotent-1'];

    // Both requests return 201: the replay serves the stored creation
    // response (status code + body), including the same raw key.
    $first = test()->postJson('/api/v1/api-keys', $payload, $headers)->assertCreated();
    $second = test()->postJson('/api/v1/api-keys', $payload, $headers)->assertCreated();

    $firstRef = $first->json('data.reference');
    $secondRef = $second->json('data.reference');

    expect($firstRef)->toBe($secondRef)
        ->and($first->json('data.raw_key'))->toBe($second->json('data.raw_key'))
        ->and(ApiKey::where('reference', $firstRef)->count())->toBe(1)
        ->and(AuditEvent::where('event', AuditEventName::ApiKeyCreated->value)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Audit logging
// ---------------------------------------------------------------------------

it('logs api_key.created exactly once with no sensitive metadata', function () {
    [, $rawKey] = apiKeysMerchant();

    apiKeysCall('POST', '', [], $rawKey, ['name' => 'Audit Test Key']);

    $event = AuditEvent::where('event', AuditEventName::ApiKeyCreated->value)->latest('id')->first();

    expect(AuditEvent::where('event', AuditEventName::ApiKeyCreated->value)->count())->toBe(1)
        ->and($event->outcome?->value)->toBe(AuditOutcome::Success->value)
        ->and($event->response_status)->toBe(201);

    $metadata = $event->metadata;

    expect($metadata)->toBeEmpty()
        ->and(json_encode($event->getAttributes()))->not->toContain($rawKey);
});

it('logs api_key.revoked exactly once', function () {
    [, $rawKey] = apiKeysMerchant();

    $reference = apiKeysCall('POST', '', [], $rawKey, ['name' => 'Revoke Log Key'])->json('data.reference');

    $before = AuditEvent::where('event', AuditEventName::ApiKeyRevoked->value)->count();

    apiKeysCall('POST', '/'.$reference.'/revoke', [], $rawKey)->assertOk();

    expect(AuditEvent::where('event', AuditEventName::ApiKeyRevoked->value)->count())->toBe($before + 1)
        ->and(AuditEvent::where('event', AuditEventName::ApiKeyRevoked->value)
            ->where('outcome', AuditOutcome::Success->value)
            ->exists())->toBeTrue();
});

it('does not log audit events for key reads, list, or show', function () {
    [, $rawKey] = apiKeysMerchant();

    $reference = apiKeysCall('POST', '', [], $rawKey, ['name' => 'Read Test Key'])->json('data.reference');

    $before = AuditEvent::count();

    apiKeysCall('GET', '', [], $rawKey)->assertOk();
    apiKeysCall('GET', '/'.$reference, [], $rawKey)->assertOk();

    expect(AuditEvent::count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Regression
// ---------------------------------------------------------------------------

it('regression: existing payment endpoints still work with new keys', function () {
    [, $rawKey] = apiKeysMerchant();

    $payment = test()->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeysAuth($rawKey))
        ->assertCreated()
        ->json('data.reference');

    expect($payment)->toStartWith('pay_');
});

it('regression: webhook routes remain unchanged', function () {
    expect(route('api.v1.webhooks.handle', ['provider' => 'stripe']))->toContain('/webhooks/stripe');
});
