<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\AuditEventName;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Merchant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * (apiKeyRot-prefixed helpers avoid clashing with sibling test files
 * under the same Pest process.)
 *
 * @return array{0: Merchant, 1: string}
 */
function apiKeyRotMerchant(string $name = 'Rotation Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);

    return [$merchant, app(CreateApiKey::class)->create($merchant, 'CI/CD')->rawKey];
}

function apiKeyRotAuth(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

function apiKeyRotRotate(string $path, ?string $rawKey = null, array $body = [], array $headers = []): TestResponse
{
    $auth = $rawKey !== null ? apiKeyRotAuth($rawKey) : [];

    return test()->postJson('/api/v1/api-keys'.$path, $body, $auth + $headers);
}

// ---------------------------------------------------------------------------
// Rotation basics
// ---------------------------------------------------------------------------

it('rotates a key: new raw secret once, old key revoked, replacement authenticates', function () {
    [$merchant, $rawKey] = apiKeyRotMerchant();

    $old = app(CreateApiKey::class)->create($merchant, 'Old Key', 'legacy');
    $oldReference = $old->apiKey->reference;

    // Old key works before rotation.
    $this->getJson('/api/v1/me', apiKeyRotAuth($old->rawKey))->assertOk();

    $response = apiKeyRotRotate('/'.$oldReference.'/rotate', $rawKey)->assertCreated();

    $newReference = $response->json('data.reference');
    $newRaw = $response->json('data.raw_key');

    // New raw secret returned exactly once, and it authenticates.
    expect($newReference)->toStartWith('key_')
        ->and($newReference)->not->toBe($oldReference)
        ->and($newRaw)->toStartWith('sk_test_')
        ->and($newRaw)->not->toBe($old->rawKey);

    $this->getJson('/api/v1/me', apiKeyRotAuth($newRaw))->assertOk();

    // Old key is revoked and can no longer authenticate (generic 401).
    expect($old->apiKey->fresh()->isRevoked())->toBeTrue();

    $this->getJson('/api/v1/me', apiKeyRotAuth($old->rawKey))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);

    // The revoking (bootstrap) key still works.
    $this->getJson('/api/v1/me', apiKeyRotAuth($rawKey))->assertOk();
});

it('allows rotating the currently-authenticated key', function () {
    [$merchant, $rawKey] = apiKeyRotMerchant();

    // The bootstrap key itself is the rotation target.
    $reference = ApiKey::query()
        ->where('merchant_id', $merchant->id)
        ->where('key_hash', '!=', '')
        ->orderBy('id')
        ->first()
        ->reference;

    $response = apiKeyRotRotate('/'.$reference.'/rotate', $rawKey)->assertCreated();

    $newRaw = $response->json('data.raw_key');

    // The replacement authenticates immediately.
    $this->getJson('/api/v1/me', apiKeyRotAuth($newRaw))->assertOk();

    // The old (rotated-away) key no longer authenticates.
    $this->getJson('/api/v1/me', apiKeyRotAuth($rawKey))->assertUnauthorized();
});

it('inherits the old key name and label on the replacement', function () {
    [$merchant, $rawKey] = apiKeyRotMerchant();

    $old = app(CreateApiKey::class)->create($merchant, 'Prod Server', 'eu-west');

    $data = apiKeyRotRotate('/'.$old->apiKey->reference.'/rotate', $rawKey)
        ->assertCreated()
        ->json('data');

    expect($data['name'])->toBe('Prod Server')
        ->and($data['label'])->toBe('eu-west');
});

// ---------------------------------------------------------------------------
// Isolation
// ---------------------------------------------------------------------------

it('rejects cross-merchant rotation identically to an unknown reference', function () {
    [$merchantA, $keyA] = apiKeyRotMerchant('Merchant A');
    [$merchantB, $keyB] = apiKeyRotMerchant('Merchant B');

    $bReference = app(CreateApiKey::class)->create($merchantB, 'B Key')->apiKey->reference;

    $unknown = apiKeyRotRotate('/key_unknown/rotate', $keyA);
    $cross = apiKeyRotRotate('/'.$bReference.'/rotate', $keyA);

    $unknown->assertNotFound();
    $cross->assertNotFound();

    expect($unknown->json())->toBe($cross->json())
        ->and($unknown->json())->toBe(['message' => 'Not found.']);

    // Merchant B's key is untouched and still authenticates.
    expect($merchantB->apiKeys()->where('reference', $bReference)->first()->isRevoked())->toBeFalse();
    $this->getJson('/api/v1/me', apiKeyRotAuth($keyB))->assertOk();
});

it('ignores merchant_id supplied in the rotation body', function () {
    [$merchantA, $keyA] = apiKeyRotMerchant('Merchant A');
    [$merchantB] = apiKeyRotMerchant('Merchant B');

    $oldReference = app(CreateApiKey::class)->create($merchantA, 'A Key')->apiKey->reference;

    $response = apiKeyRotRotate('/'.$oldReference.'/rotate', $keyA, [
        'merchant_id' => $merchantB->id,
    ])->assertCreated();

    $newReference = $response->json('data.reference');

    expect($merchantA->apiKeys()->where('reference', $newReference)->exists())->toBeTrue()
        ->and($merchantB->apiKeys()->where('reference', $newReference)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Response security
// ---------------------------------------------------------------------------

it('never exposes hashes or internal identifiers in the rotation response', function () {
    [$merchant, $rawKey] = apiKeyRotMerchant();

    $oldReference = app(CreateApiKey::class)->create($merchant, 'Secure Key')->apiKey->reference;

    $payload = apiKeyRotRotate('/'.$oldReference.'/rotate', $rawKey)->assertCreated()->json('data');

    expect($payload)->not->toHaveKey('id')
        ->and($payload)->not->toHaveKey('merchant_id')
        ->and($payload)->not->toHaveKey('key_hash')
        ->and($payload)->not->toHaveKey('key_prefix');

    // The raw secret never appears in list or show afterwards.
    $listContent = $this->getJson('/api/v1/api-keys', apiKeyRotAuth($rawKey))->getContent();
    $showContent = $this->getJson('/api/v1/api-keys/'.$payload['reference'], apiKeyRotAuth($rawKey))->getContent();

    expect($listContent)->not->toContain($payload['raw_key'])
        ->and($showContent)->not->toContain($payload['raw_key'])
        ->and($showContent)->not->toContain('key_hash');
});

// ---------------------------------------------------------------------------
// Audit logging
// ---------------------------------------------------------------------------

it('logs api_key.rotated exactly once with no secrets in metadata', function () {
    [$merchant, $rawKey] = apiKeyRotMerchant();

    $old = app(CreateApiKey::class)->create($merchant, 'Audit Rotation');

    $response = apiKeyRotRotate('/'.$old->apiKey->reference.'/rotate', $rawKey)->assertCreated();
    $newRaw = $response->json('data.raw_key');

    $events = AuditEvent::where('event', AuditEventName::ApiKeyRotated->value)->get();

    expect($events)->toHaveCount(1)
        ->and($events[0]->outcome?->value)->toBe('success')
        ->and($events[0]->response_status)->toBe(201);

    $serialized = json_encode($events[0]->getAttributes());

    expect($serialized)->not->toContain($newRaw)
        ->and($serialized)->not->toContain($old->rawKey)
        ->and($serialized)->not->toContain($rawKey)
        ->and($serialized)->not->toContain('key_hash');

    // Only the rotation event — no duplicate api_key.created/revoked noise
    // from the internal replacement/revoke steps.
    expect(AuditEvent::where('event', AuditEventName::ApiKeyCreated->value)->count())->toBe(0)
        ->and(AuditEvent::where('event', AuditEventName::ApiKeyRevoked->value)->count())->toBe(0);
});

it('does not log audit events for rotation reads or rejected rotations', function () {
    [$merchantA, $keyA] = apiKeyRotMerchant('Merchant A');
    [$merchantB] = apiKeyRotMerchant('Merchant B');

    $bReference = app(CreateApiKey::class)->create($merchantB, 'B Key')->apiKey->reference;

    $before = AuditEvent::count();

    apiKeyRotRotate('/key_unknown/rotate', $keyA)->assertNotFound();
    apiKeyRotRotate('/'.$bReference.'/rotate', $keyA)->assertNotFound();
    $this->getJson('/api/v1/api-keys', apiKeyRotAuth($keyA))->assertOk();

    expect(AuditEvent::count())->toBe($before);
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('replays an idempotent rotation without creating a second replacement key', function () {
    [$merchant, $rawKey] = apiKeyRotMerchant();

    $old = app(CreateApiKey::class)->create($merchant, 'Idempotent Rotation');
    $headers = ['Idempotency-Key' => 'rotation-retry-1'];

    $first = apiKeyRotRotate('/'.$old->apiKey->reference.'/rotate', $rawKey, [], $headers)->assertCreated();
    $second = apiKeyRotRotate('/'.$old->apiKey->reference.'/rotate', $rawKey, [], $headers)->assertCreated();

    // Identical stored response: same replacement, same one-time secret.
    expect($second->json('data.reference'))->toBe($first->json('data.reference'))
        ->and($second->json('data.raw_key'))->toBe($first->json('data.raw_key'));

    // Exactly one replacement exists; the old key is revoked.
    $replacements = $merchant->apiKeys()
        ->where('name', 'Idempotent Rotation')
        ->where('reference', '!=', $old->apiKey->reference)
        ->get();

    expect($replacements)->toHaveCount(1)
        ->and($old->apiKey->fresh()->isRevoked())->toBeTrue();

    // Exactly one rotation audit event despite the retry.
    expect(AuditEvent::where('event', AuditEventName::ApiKeyRotated->value)->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------

it('routes rotation through the sensitive bucket, independent of reads', function () {
    config(['rate_limiting.buckets.sensitive.max_attempts' => 1]);
    [$merchant, $rawKey] = apiKeyRotMerchant();

    $first = app(CreateApiKey::class)->create($merchant, 'Rate Target');

    apiKeyRotRotate('/'.$first->apiKey->reference.'/rotate', $rawKey)->assertCreated();

    // Second sensitive write within the window is limited — use create.
    $this->postJson('/api/v1/api-keys', ['name' => 'Limited'], apiKeyRotAuth($rawKey))
        ->assertStatus(429);

    // Reads remain on the independent standard bucket.
    $this->getJson('/api/v1/api-keys', apiKeyRotAuth($rawKey))->assertOk();
});

// ---------------------------------------------------------------------------
// Regression
// ---------------------------------------------------------------------------

it('regression: rotation does not disturb create/show/revoke flows', function () {
    [$merchant, $rawKey] = apiKeyRotMerchant();

    $created = $this->postJson('/api/v1/api-keys', ['name' => 'Still Works'], apiKeyRotAuth($rawKey))
        ->assertCreated();
    $reference = $created->json('data.reference');

    expect(AuditEvent::where('event', AuditEventName::ApiKeyCreated->value)->count())->toBe(1);

    $this->getJson('/api/v1/api-keys/'.$reference, apiKeyRotAuth($rawKey))->assertOk();

    $this->postJson('/api/v1/api-keys/'.$reference.'/revoke', [], apiKeyRotAuth($rawKey))->assertOk();

    expect(ApiKey::where('reference', $reference)->first()->isRevoked())->toBeTrue()
        ->and(AuditEvent::where('event', AuditEventName::ApiKeyRevoked->value)->count())->toBe(1)
        ->and(AuditEvent::where('event', AuditEventName::ApiKeyRotated->value)->count())->toBe(0);
});

it('regression: expired keys still fail generically', function () {
    [$merchant] = apiKeyRotMerchant();

    $rawKey = CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH);

    ApiKey::factory()->withRawKey($rawKey)->expired()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Expired',
    ]);

    $this->getJson('/api/v1/me', apiKeyRotAuth($rawKey))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});
