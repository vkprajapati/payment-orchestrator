<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\ApiKeyScope;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * (apiKeyScopesMgmt-prefixed helpers avoid clashing with sibling test
 * files under the same Pest process.)
 *
 * Create a merchant and a full-access bootstrap key (authenticates for
 * create/update/revoke/rotate flows).
 *
 * @return array{0: Merchant, 1: string}
 */
function apiKeyScopesMgmtMerchant(string $name = 'Scopes Mgmt Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);

    return [$merchant, app(CreateApiKey::class)->create($merchant, 'Bootstrap')->rawKey];
}

function apiKeyScopesMgmtAuth(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

/**
 * PUT /api/v1/api-keys/{reference}/scopes.
 *
 * @param  list<string>  $scopes
 */
function apiKeyScopesMgmtPut(string $reference, string $rawKey, array $scopes, array $extra = []): TestResponse
{
    return test()->putJson(
        '/api/v1/api-keys/'.$reference.'/scopes',
        array_merge(['scopes' => $scopes], $extra),
        apiKeyScopesMgmtAuth($rawKey),
    );
}

// ---------------------------------------------------------------------------
// Successful scope updates
// ---------------------------------------------------------------------------

it('updates an existing key scopes with full access authentication', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    $newScopes = ['payments:read', 'payments:write'];

    apiKeyScopesMgmtPut($reference, $fullKey, $newScopes)->assertOk();

    $this->assertDatabaseHas('api_keys', [
        'reference' => $reference,
        'scopes' => json_encode($newScopes),
    ]);
});

it('requires api_keys:write to update scopes and denies insufficient-scope keys', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    // Create a target key using the full key.
    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    // Create a read-only key on the same merchant.
    $readKey = app(CreateApiKey::class)->create($merchant, 'Read Only', null, null, [
        'payments:read',
    ])->rawKey;

    apiKeyScopesMgmtPut($reference, $readKey, ['payments:read', 'payments:write'])
        ->assertForbidden();
});

it('allows updating its own scopes (self-scope-removal succeeds, later requests enforce)', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    // Create a key that has api_keys:write and api_keys:read.
    $selfKey = app(CreateApiKey::class)->create($merchant, 'Self Key', null, null, [
        'api_keys:write',
        'api_keys:read',
    ])->rawKey;

    // Resolve this key's own reference via the list endpoint.
    $reference = $this->getJson('/api/v1/api-keys', apiKeyScopesMgmtAuth($selfKey))
        ->assertOk()
        ->json('data.0.reference');

    // This request is authorized under the previous scope set; it completes.
    apiKeyScopesMgmtPut($reference, $selfKey, ['api_keys:read'])->assertOk();

    // The self-key no longer has api_keys:write -> later requests 403.
    $this->putJson(
        '/api/v1/api-keys/'.$reference.'/scopes',
        ['scopes' => ['api_keys:read', 'api_keys:write']],
        apiKeyScopesMgmtAuth($selfKey),
    )->assertForbidden();
});

// ---------------------------------------------------------------------------
// Validation failures
// ---------------------------------------------------------------------------

it('rejects invalid, non-array, duplicate, and empty scopes', function (array $body, string $field) {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    $this->putJson('/api/v1/api-keys/'.$reference.'/scopes', $body, apiKeyScopesMgmtAuth($fullKey))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'invalid scope value' => [['scopes' => ['payments:destroy']], 'scopes.0'],
    'non-array scopes' => [['scopes' => 'payments:read'], 'scopes'],
    'duplicate scopes' => [['scopes' => ['payments:read', 'payments:read']], 'scopes.0'],
    'empty scopes array' => [['scopes' => []], 'scopes'],
    'missing scopes' => [[], 'scopes'],
]);
// ---------------------------------------------------------------------------
// Merchant isolation
// ---------------------------------------------------------------------------

it('returns identical generic 404 for unknown and cross-merchant references', function () {
    [$merchantA, $keyA] = apiKeyScopesMgmtMerchant('Merchant A');
    [$merchantB] = apiKeyScopesMgmtMerchant('Merchant B');

    // Create a genuine B key via B's own bootstrap.
    $bKey = app(CreateApiKey::class)->create($merchantB, 'B Real')->rawKey;
    $refB = $this->postJson('/api/v1/api-keys', ['name' => 'B Real Key'], apiKeyScopesMgmtAuth($bKey))
        ->assertCreated()
        ->json('data.reference');

    $unknownRef = 'key_'.Str::ulid();

    $cross = apiKeyScopesMgmtPut($refB, $keyA, ['payments:read']);
    $unknown = apiKeyScopesMgmtPut($unknownRef, $keyA, ['payments:read']);

    expect($cross->status())->toBe(404)
        ->and($unknown->status())->toBe(404)
        ->and($cross->json())->toBe($unknown->json())
        ->and($cross->json('message'))->toBe('Not found.');
});

it('never accepts merchant_id from request input', function () {
    [$merchantA, $keyA] = apiKeyScopesMgmtMerchant('Merchant A');
    [$merchantB] = apiKeyScopesMgmtMerchant('Merchant B');

    // Create a target key on A.
    $refA = $this->postJson('/api/v1/api-keys', ['name' => 'A Target'], apiKeyScopesMgmtAuth($keyA))
        ->assertCreated()
        ->json('data.reference');

    // Attempt to redirect the update to merchant B through input.
    apiKeyScopesMgmtPut($refA, $keyA, ['payments:read'], ['merchant_id' => $merchantB->id])
        ->assertOk();

    // The key on A is updated; B has no such key.
    expect($merchantA->apiKeys()->where('reference', $refA)->first()->scopes)->toBe(['payments:read'])
        ->and($merchantB->apiKeys()->where('reference', $refA)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Authentication ordering
// ---------------------------------------------------------------------------

it('returns generic 401 for invalid, revoked, and expired keys before scope checks', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    // A totally invalid key.
    $invalid = 'sk_test_'.Str::random(40);

    // A valid-format revoked key.
    $revokedRaw = 'sk_test_'.Str::random(40);
    ApiKey::factory()->for($merchant, 'merchant')
        ->withRawKey($revokedRaw)->revoked()->create();

    // A valid-format expired key.
    $expiredRaw = 'sk_test_'.Str::random(40);
    ApiKey::factory()->for($merchant, 'merchant')
        ->withRawKey($expiredRaw)->expired()->create();

    foreach ([$invalid, $revokedRaw, $expiredRaw] as $rawKey) {
        apiKeyScopesMgmtPut($reference, $rawKey, ['payments:read'])
            ->assertUnauthorized()
            ->assertJson(['message' => 'Invalid API key.']);
    }
});
// ---------------------------------------------------------------------------
// Response security
// ---------------------------------------------------------------------------

it('never exposes internal or security fields in the update response', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    $response = apiKeyScopesMgmtPut($reference, $fullKey, ['payments:read'])->assertOk();

    $data = $response->json('data');

    expect($data)->not->toHaveKey('id')
        ->and($data)->not->toHaveKey('merchant_id')
        ->and($data)->not->toHaveKey('key_hash')
        ->and($data)->not->toHaveKey('key_prefix')
        ->and($data)->not->toHaveKey('raw_key')
        ->and($data['reference'])->toBe($reference)
        ->and($data['scopes'])->toBe(['payments:read']);
});

it('does not rotate or alter the raw secret when scopes change', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    // Create a key whose raw secret we hold (so we can authenticate later).
    $created = app(CreateApiKey::class)->create($merchant, 'Known Secret', null, null, null);
    $knownRef = $created->apiKey->reference;
    $hashBefore = $created->apiKey->key_hash;

    // Update its scopes (keeping account:read so it can still call /me).
    $this->putJson(
        '/api/v1/api-keys/'.$knownRef.'/scopes',
        ['scopes' => ['account:read', 'payments:read']],
        apiKeyScopesMgmtAuth($fullKey),
    )->assertOk();

    // The secret's hash is unchanged and the key still authenticates.
    expect($created->apiKey->refresh()->key_hash)->toBe($hashBefore)
        ->and($this->getJson('/api/v1/me', apiKeyScopesMgmtAuth($created->rawKey))->assertOk());
});

// ---------------------------------------------------------------------------
// Audit behavior
// ---------------------------------------------------------------------------

it('logs api_key.scopes_updated exactly once per actual change with safe metadata', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    apiKeyScopesMgmtPut($reference, $fullKey, ['payments:read'])->assertOk();

    $audit = AuditEvent::query()
        ->where('event', AuditEventName::ApiKeyScopesUpdated->value)
        ->get();

    expect($audit)->toHaveCount(1)
        ->and($audit[0]->outcome->value)->toBe(AuditOutcome::Success->value)
        ->and($audit[0]->response_status)->toBe(200)
        ->and($audit[0]->merchant_id)->toBe($merchant->id);

    $metadata = $audit[0]->metadata;

    expect($metadata['scopes'])->toBe(['payments:read'])
        ->and($metadata['old_scopes'] ?? null)->toBeArray();
});

it('does not log scope updates when the scope set is unchanged (no audit noise)', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    $scopes = ['payments:read', 'refunds:read'];

    apiKeyScopesMgmtPut($reference, $fullKey, $scopes)->assertOk();
    apiKeyScopesMgmtPut($reference, $fullKey, $scopes)->assertOk(); // identical re-apply

    $count = AuditEvent::query()
        ->where('event', AuditEventName::ApiKeyScopesUpdated->value)
        ->count();

    expect($count)->toBe(1);
});

it('never includes raw keys or hashes in the scope-update audit metadata', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    apiKeyScopesMgmtPut($reference, $fullKey, ['payments:read'])->assertOk();

    $audit = AuditEvent::query()
        ->where('event', AuditEventName::ApiKeyScopesUpdated->value)
        ->firstOrFail();

    $serialized = json_encode($audit->attributesToArray());

    expect($serialized)->not->toContain('sk_test_')
        ->and($serialized)->not->toContain('$2y$')
        ->and($serialized)->not->toContain($fullKey);
});
// ---------------------------------------------------------------------------
// Idempotency / deterministic replay
// ---------------------------------------------------------------------------

it('applying the same scope set twice is a deterministic no-op', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    $scopes = ['payments:read'];

    apiKeyScopesMgmtPut($reference, $fullKey, $scopes)->assertOk();

    $key = ApiKey::where('reference', $reference)->firstOrFail();
    $firstUpdatedAt = $key->updated_at->format('Y-m-d H:i:s.u');

    apiKeyScopesMgmtPut($reference, $fullKey, $scopes)->assertOk();

    $key->refresh();

    expect($key->scopes)->toBe($scopes)
        ->and($key->updated_at->format('Y-m-d H:i:s.u'))->toBe($firstUpdatedAt)
        ->and(AuditEvent::where('event', AuditEventName::ApiKeyScopesUpdated->value)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Rate limiting and middleware mapping
// ---------------------------------------------------------------------------

it('routes the scope update endpoint through the sensitive bucket with scope enforcement', function () {
    $route = Route::getRoutes()->getByName('api.v1.api-keys.scopes.update');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('api.key', 'scope:api_keys:write', 'throttle:sensitive');
});

it('enforces the sensitive bucket on the scope update endpoint', function () {
    config(['rate_limiting.buckets.sensitive.max_attempts' => 3]);
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    // NOTE: the creation POST below consumes one sensitive-bucket attempt,
    // so with a limit of 3 the first two PUTs (attempts 2 and 3) succeed
    // and the third PUT exceeds the bucket.
    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    // First two succeed within the configured small limit.
    apiKeyScopesMgmtPut($reference, $fullKey, ['payments:read'])->assertOk();
    apiKeyScopesMgmtPut($reference, $fullKey, ['payments:read', 'refunds:read'])->assertOk();

    // Third hits the sensitive bucket limit.
    apiKeyScopesMgmtPut($reference, $fullKey, ['payments:read'])->assertStatus(429);

    // A standard read is on a different budget and still works.
    $this->getJson('/api/v1/api-keys', apiKeyScopesMgmtAuth($fullKey))->assertOk();
});

// ---------------------------------------------------------------------------
// Regression: other lifecycle flows remain intact
// ---------------------------------------------------------------------------

it('regression: create, list, show, revoke and rotate continue working after scope changes', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    // Alter this key's scopes (narrowing), then confirm it still shows up.
    apiKeyScopesMgmtPut($reference, $fullKey, ['api_keys:read'])->assertOk();

    $this->getJson('/api/v1/api-keys/'.$reference, apiKeyScopesMgmtAuth($fullKey))
        ->assertOk()
        ->assertJsonPath('data.scopes', ['api_keys:read']);

    $this->getJson('/api/v1/api-keys', apiKeyScopesMgmtAuth($fullKey))
        ->assertOk()
        ->assertJsonPath('data.0.reference', $reference);

    // Create a fresh rotatable key.
    $rotRef = $this->postJson('/api/v1/api-keys', ['name' => 'Rotate Me'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    // Rotate requires the target to have scopes; rotation inherits them exactly.
    $this->postJson('/api/v1/api-keys/'.$rotRef.'/rotate', [], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->assertJsonPath('data.scopes', ApiKeyScope::values());

    // Revoke still works.
    $this->postJson('/api/v1/api-keys/'.$rotRef.'/revoke', [], apiKeyScopesMgmtAuth($fullKey))
        ->assertOk();
});

it('regression: reads (list/show) create zero audit events', function () {
    [$merchant, $fullKey] = apiKeyScopesMgmtMerchant();

    $reference = $this->postJson('/api/v1/api-keys', ['name' => 'Target'], apiKeyScopesMgmtAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    apiKeyScopesMgmtPut($reference, $fullKey, ['payments:read'])->assertOk();

    $before = AuditEvent::count();

    $this->getJson('/api/v1/api-keys', apiKeyScopesMgmtAuth($fullKey))->assertOk();
    $this->getJson('/api/v1/api-keys/'.$reference, apiKeyScopesMgmtAuth($fullKey))->assertOk();

    expect(AuditEvent::count())->toBe($before);
});
