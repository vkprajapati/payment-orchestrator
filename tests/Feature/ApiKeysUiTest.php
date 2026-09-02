<?php

use App\Enums\ApiKeyScope;
use App\Enums\AuditEventName;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Merchant;
use App\Models\User;
use App\Services\Merchants\CurrentMerchant;
use Database\Factories\ApiKeyFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant and an attached member with the given role.
 *
 * @return array{0: Merchant, 1: User}
 */
function uiKeyMember(string $role = 'owner'): array
{
    $merchant = Merchant::factory()->create(['name' => 'Acme Inc.', 'slug' => 'acme-inc']);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => $role]);

    return [$merchant, $user];
}

function actingAsUiKeyMember(Merchant $merchant, User $user): TestCase
{
    return test()->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user);
}

function createUiKey(Merchant $merchant, array $attributes = []): ApiKey
{
    // merchant_id is forced last so caller-supplied factory attributes
    // (which carry a nested factory for merchant_id) can never relocate
    // the key to another merchant.
    $attributes['merchant_id'] = $merchant->id;

    return ApiKey::factory()->create($attributes);
}

// ---------------------------------------------------------------------------
// Authentication & navigation
// ---------------------------------------------------------------------------

it('requires authentication to view API keys', function () {
    $this->get('/settings/api-keys')->assertRedirect(route('login'));
});

it('shows the API keys page for a managing member', function () {
    [$merchant, $user] = uiKeyMember();

    actingAsUiKeyMember($merchant, $user)
        ->get('/settings/api-keys')
        ->assertOk()
        ->assertSee('API Keys');
});

it('exposes API keys in the application navigation', function () {
    [$merchant, $user] = uiKeyMember();

    actingAsUiKeyMember($merchant, $user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee(route('settings.api-keys.index'));
});

it('forbids viewers from API key management', function () {
    [$merchant, $user] = uiKeyMember('viewer');

    actingAsUiKeyMember($merchant, $user)
        ->get('/settings/api-keys')
        ->assertForbidden();
});

it('lists only the current merchant keys with safe metadata', function () {
    [$merchantA, $userA] = uiKeyMember();
    $merchantB = Merchant::factory()->create(['slug' => 'other-co']);

    $ownKey = createUiKey($merchantA, ['name' => 'Own Key', 'label' => 'prod']);
    $foreign = createUiKey($merchantB, ['name' => 'Foreign Key']);

    actingAsUiKeyMember($merchantA, $userA)
        ->get('/settings/api-keys')
        ->assertOk()
        ->assertSee('Own Key')
        ->assertSee($ownKey->reference)
        ->assertDontSee('Foreign Key')
        ->assertDontSee($foreign->reference);
});

// ---------------------------------------------------------------------------
// Creation & one-time secret
// ---------------------------------------------------------------------------

it('renders the create form with all scopes available', function () {
    [$merchant, $user] = uiKeyMember();

    $response = actingAsUiKeyMember($merchant, $user)->get('/settings/api-keys');

    foreach (ApiKeyScope::values() as $scope) {
        $response->assertSee('value="'.$scope.'"', false);
    }
});

it('creates a key without scopes and grants full access by default', function () {
    [$merchant, $user] = uiKeyMember();

    actingAsUiKeyMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Production Server'])
        ->assertRedirect();

    $apiKey = $merchant->apiKeys()->firstOrFail();

    expect($apiKey->label)->toBeNull()
        ->and($apiKey->expires_at)->toBeNull()
        ->and($apiKey->scopes)->toEqual(ApiKeyScope::values())
        ->and($apiKey->reference)->toStartWith('key_');
});

it('creates a key with label, expiration, and explicit scopes', function () {
    [$merchant, $user] = uiKeyMember();
    $expires = now()->addDays(30)->toDateString();

    actingAsUiKeyMember($merchant, $user)->post('/settings/api-keys', [
        'name' => 'Mobile App',
        'label' => 'eu-west-1',
        'expires_at' => $expires,
        'scopes_submitted' => '1',
        'scopes' => ['payments:read', 'payments:write'],
    ]);

    $apiKey = $merchant->apiKeys()->firstOrFail();

    expect($apiKey->label)->toBe('eu-west-1')
        ->and($apiKey->expires_at?->toDateString())->toBe($expires)
        ->and($apiKey->scopes)->toEqual(['payments:read', 'payments:write']);
});

it('rejects an empty scope selection on creation', function () {
    [$merchant, $user] = uiKeyMember();

    actingAsUiKeyMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'No Access', 'scopes_submitted' => '1'])
        ->assertSessionHasErrors('scopes');

    expect($merchant->apiKeys()->count())->toBe(0);
});

it('rejects invalid and duplicate scopes on creation', function () {
    [$merchant, $user] = uiKeyMember();

    actingAsUiKeyMember($merchant, $user)
        ->post('/settings/api-keys', [
            'name' => 'Bad',
            'scopes_submitted' => '1',
            'scopes' => ['payments:read', 'payments:unknown'],
        ])->assertSessionHasErrors('scopes.*');

    actingAsUiKeyMember($merchant, $user)
        ->post('/settings/api-keys', [
            'name' => 'Dup',
            'scopes_submitted' => '1',
            'scopes' => ['payments:read', 'payments:read'],
        ])->assertSessionHasErrors('scopes.0');

    expect($merchant->apiKeys()->count())->toBe(0);
});

it('rejects a past expiration date on creation', function () {
    [$merchant, $user] = uiKeyMember();

    actingAsUiKeyMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Past', 'expires_at' => now()->subDay()->toDateString()])
        ->assertSessionHasErrors('expires_at');

    expect($merchant->apiKeys()->count())->toBe(0);
});

it('shows the raw secret exactly once on the one-time page', function () {
    [$merchant, $user] = uiKeyMember();

    actingAsUiKeyMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Once Only']);

    $apiKey = $merchant->apiKeys()->firstOrFail();
    $rawKey = session('api_key_created')['raw'];

    expect($rawKey)->toStartWith('sk_test_')->toHaveLength(48);

    actingAsUiKeyMember($merchant, $user)
        ->get(route('settings.api-keys.created', $apiKey))
        ->assertOk()
        ->assertSee($rawKey)
        ->assertSee('will not be shown again', false);

    expect(session('api_key_created'))->toBeNull();

    actingAsUiKeyMember($merchant, $user)
        ->get(route('settings.api-keys.created', $apiKey))
        ->assertRedirect(route('settings.api-keys.index'));
});

// ---------------------------------------------------------------------------
// Key detail & scope management
// ---------------------------------------------------------------------------

it('shows key details and current scopes on the manage page', function () {
    [$merchant, $user] = uiKeyMember();
    $apiKey = createUiKey($merchant, [
        'name' => 'Scoped Key',
        'label' => 'billing',
        'scopes' => ['payments:read', 'refunds:write'],
    ]);

    actingAsUiKeyMember($merchant, $user)
        ->get(route('settings.api-keys.show', $apiKey))
        ->assertOk()
        ->assertSee('Scoped Key')
        ->assertSee($apiKey->reference)
        ->assertSee('billing')
        ->assertSee('payments:read', false)
        ->assertSee('refunds:write', false);
});

it('updates key scopes and persists them exactly', function () {
    [$merchant, $user] = uiKeyMember();
    $apiKey = createUiKey($merchant, ['scopes' => ['payments:read']]);

    actingAsUiKeyMember($merchant, $user)
        ->put(route('settings.api-keys.scopes', $apiKey), [
            'scopes' => ['payments:read', 'refunds:read', 'audit:read'],
        ])
        ->assertRedirect(route('settings.api-keys.show', $apiKey))
        ->assertSessionHas('status', 'API key scopes updated.');

    expect($apiKey->refresh()->scopes)->toEqual(['payments:read', 'refunds:read', 'audit:read']);
});

it('does not change the secret or hash when updating scopes', function () {
    [$merchant, $user] = uiKeyMember();
    $rawKey = 'sk_test_'.Str::random(40);
    $apiKey = createUiKey($merchant, ApiKeyFactory::attributesForRawKey($rawKey));
    $hashBefore = $apiKey->key_hash;

    actingAsUiKeyMember($merchant, $user)
        ->put(route('settings.api-keys.scopes', $apiKey), ['scopes' => ['account:read', 'audit:read']]);

    expect($apiKey->refresh()->key_hash)->toBe($hashBefore);

    // The key still authenticates with the same secret.
    $this->withHeader('Authorization', 'Bearer '.$rawKey)
        ->getJson('/api/v1/me')
        ->assertOk();
});

it('creates no audit event for a no-op scope update', function () {
    [$merchant, $user] = uiKeyMember();
    $apiKey = createUiKey($merchant, ['scopes' => ['payments:read']]);

    actingAsUiKeyMember($merchant, $user)
        ->put(route('settings.api-keys.scopes', $apiKey), ['scopes' => ['payments:read']])
        ->assertRedirect();

    expect(AuditEvent::where('event', AuditEventName::ApiKeyScopesUpdated->value)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Rotation
// ---------------------------------------------------------------------------

it('rotates a key and shows the new secret exactly once', function () {
    [$merchant, $user] = uiKeyMember();
    $oldRaw = 'sk_test_'.Str::random(40);
    $oldKey = createUiKey($merchant, array_merge(
        ApiKeyFactory::attributesForRawKey($oldRaw),
        ['name' => 'Rotate Me', 'label' => 'legacy', 'scopes' => ['account:read', 'payments:read', 'refunds:read']],
    ));

    actingAsUiKeyMember($merchant, $user)
        ->post(route('settings.api-keys.rotate', $oldKey))
        ->assertRedirect();

    $replacement = $merchant->apiKeys()->whereKeyNot($oldKey->id)->firstOrFail();
    $newRaw = session('api_key_created')['raw'];

    expect($oldKey->refresh()->isRevoked())->toBeTrue()
        ->and($replacement->name)->toBe('Rotate Me')
        ->and($replacement->label)->toBe('legacy')
        ->and($replacement->scopes)->toEqual(['account:read', 'payments:read', 'refunds:read'])
        ->and(Hash::check($newRaw, $replacement->key_hash))->toBeTrue()
        ->and($replacement->isRevoked())->toBeFalse();

    actingAsUiKeyMember($merchant, $user)
        ->get(route('settings.api-keys.created', $replacement))
        ->assertOk()
        ->assertSee($newRaw)
        ->assertSee('rotated')
        ->assertSee('has been revoked');

    // The old key can no longer authenticate; the replacement can.
    $this->withHeader('Authorization', 'Bearer '.$oldRaw)
        ->getJson('/api/v1/me')->assertUnauthorized();

    $this->withHeader('Authorization', 'Bearer '.$newRaw)
        ->getJson('/api/v1/me')->assertOk();
});

// ---------------------------------------------------------------------------
// Revocation & status display
// ---------------------------------------------------------------------------

it('revokes a key after confirmation and keeps history', function () {
    [$merchant, $user] = uiKeyMember();
    $apiKey = createUiKey($merchant, ['name' => 'Doomed']);

    actingAsUiKeyMember($merchant, $user)
        ->delete(route('settings.api-keys.destroy', $apiKey))
        ->assertRedirect(route('settings.api-keys.index'))
        ->assertSessionHas('status', 'API key revoked successfully.');

    expect($apiKey->refresh()->isRevoked())->toBeTrue()
        ->and(ApiKey::query()->whereKey($apiKey->id)->count())->toBe(1)
        ->and(AuditEvent::where('event', AuditEventName::ApiKeyRevoked->value)->count())->toBe(1);

    // Repeated revocation is idempotent — no second audit event.
    actingAsUiKeyMember($merchant, $user)
        ->delete(route('settings.api-keys.destroy', $apiKey))
        ->assertRedirect();

    expect(AuditEvent::where('event', AuditEventName::ApiKeyRevoked->value)->count())->toBe(1);
});

it('renders revoke confirmation dialogs on the list and detail pages', function () {
    [$merchant, $user] = uiKeyMember();
    $apiKey = createUiKey($merchant);

    $message = 'Revoke this API key? Requests using it will stop working. This cannot be undone.';

    actingAsUiKeyMember($merchant, $user)
        ->get('/settings/api-keys')
        ->assertOk()
        ->assertSee('data-confirm', false)
        ->assertSee($message, false);

    actingAsUiKeyMember($merchant, $user)
        ->get(route('settings.api-keys.show', $apiKey))
        ->assertOk()
        ->assertSee($message, false);
});

it('displays revoked and expired keys without lifecycle actions', function () {
    [$merchant, $user] = uiKeyMember();
    $revoked = createUiKey($merchant, ['name' => 'Revoked Key', 'revoked_at' => now()]);
    $expired = createUiKey($merchant, ['name' => 'Expired Key', 'expires_at' => now()->subDay()]);

    $response = actingAsUiKeyMember($merchant, $user)->get('/settings/api-keys');

    $response->assertOk()
        ->assertSee('Revoked Key')
        ->assertSee('Expired Key');

    // Revoked/expired keys expose no rotate/revoke controls at all.
    expect($response->getContent())
        ->toContain('badge-status-suspended')
        ->toContain('badge-status-inactive')
        ->not->toContain('>Rotate</button>')
        ->not->toContain('>Revoke</button>');
});

it('displays last used information safely', function () {
    [$merchant, $user] = uiKeyMember();
    $used = createUiKey($merchant, ['name' => 'Used', 'last_used_at' => now()->subHours(2)]);
    $never = createUiKey($merchant, ['name' => 'Unused']);

    actingAsUiKeyMember($merchant, $user)
        ->get(route('settings.api-keys.show', $used))
        ->assertOk()
        ->assertSee($used->last_used_at->format('M j, Y H:i'));

    actingAsUiKeyMember($merchant, $user)
        ->get(route('settings.api-keys.show', $never))
        ->assertOk()
        ->assertSee('Never');
});

// ---------------------------------------------------------------------------
// Merchant isolation
// ---------------------------------------------------------------------------

it('denies cross-merchant and unknown keys with identical 404s', function () {
    [$merchantA, $userA] = uiKeyMember();
    $merchantB = Merchant::factory()->create(['slug' => 'other-co']);
    $foreign = createUiKey($merchantB, ['name' => 'Foreign']);

    foreach ([
        route('settings.api-keys.show', $foreign),
        route('settings.api-keys.destroy', $foreign),
    ] as $url) {
        actingAsUiKeyMember($merchantA, $userA)->get($url)->assertNotFound();
    }

    actingAsUiKeyMember($merchantA, $userA)
        ->post(route('settings.api-keys.rotate', $foreign))
        ->assertNotFound();

    actingAsUiKeyMember($merchantA, $userA)
        ->put(route('settings.api-keys.scopes', $foreign), ['scopes' => ['audit:read']])
        ->assertNotFound();

    actingAsUiKeyMember($merchantA, $userA)
        ->get(route('settings.api-keys.show', 999999))
        ->assertNotFound();

    // Nothing about the foreign key changed.
    expect($foreign->refresh()->isRevoked())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------

it('never exposes secrets, hashes, or internal identifiers in HTML', function () {
    [$merchant, $user] = uiKeyMember();
    $rawKey = 'sk_test_'.Str::random(40);
    $apiKey = createUiKey($merchant, array_merge(
        ApiKeyFactory::attributesForRawKey($rawKey),
        ['name' => 'Leak Check'],
    ));

    foreach (['/settings/api-keys', route('settings.api-keys.show', $apiKey)] as $url) {
        $html = actingAsUiKeyMember($merchant, $user)->get($url)->assertOk()->getContent();

        expect($html)
            ->not->toContain($rawKey)
            ->not->toContain($apiKey->key_hash)
            ->not->toContain('merchant_id');
    }

    // After the one-time display is consumed, no page exposes the raw key.
    actingAsUiKeyMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Second']);

    actingAsUiKeyMember($merchant, $user)->get('/settings/api-keys')->assertOk();
});

it('ignores merchant_id supplied via request input', function () {
    [$merchantA, $userA] = uiKeyMember();
    $merchantB = Merchant::factory()->create(['slug' => 'other-co']);

    actingAsUiKeyMember($merchantA, $userA)
        ->post('/settings/api-keys', ['name' => 'Hijack Attempt', 'merchant_id' => $merchantB->id])
        ->assertRedirect();

    $apiKey = $merchantA->apiKeys()->firstOrFail();

    expect($apiKey->merchant_id)->toBe($merchantA->id)
        ->and($merchantB->apiKeys()->count())->toBe(0);
});

it('audits lifecycle events exactly once without secrets', function () {
    [$merchant, $user] = uiKeyMember();
    $rawKey = 'sk_test_'.Str::random(40);
    $apiKey = createUiKey($merchant, array_merge(
        ApiKeyFactory::attributesForRawKey($rawKey),
        ['name' => 'Audited', 'scopes' => ['payments:read']],
    ));

    // Creation.
    actingAsUiKeyMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Audited Two']);

    // Scope change.
    actingAsUiKeyMember($merchant, $user)
        ->put(route('settings.api-keys.scopes', $apiKey), ['scopes' => ['refunds:read']]);

    // Rotation.
    actingAsUiKeyMember($merchant, $user)
        ->post(route('settings.api-keys.rotate', $apiKey));

    // Revocation of the now-revoked key is a no-op; revoke a fresh one instead.
    $other = createUiKey($merchant, ['name' => 'Revoke Me']);
    actingAsUiKeyMember($merchant, $user)
        ->delete(route('settings.api-keys.destroy', $other));

    $events = AuditEvent::whereIn('event', [
        AuditEventName::ApiKeyCreated->value,
        AuditEventName::ApiKeyScopesUpdated->value,
        AuditEventName::ApiKeyRotated->value,
        AuditEventName::ApiKeyRevoked->value,
    ])->get();

    expect($events->where('event', AuditEventName::ApiKeyCreated->value)->count())->toBe(1)
        ->and($events->where('event', AuditEventName::ApiKeyScopesUpdated->value)->count())->toBe(1)
        ->and($events->where('event', AuditEventName::ApiKeyRotated->value)->count())->toBe(1)
        ->and($events->where('event', AuditEventName::ApiKeyRevoked->value)->count())->toBe(1);

    // Rotation records ONLY api_key.rotated — no created/revoked noise.
    // No raw secrets or hashes anywhere in the audit trail.
    $serialized = $events->map(fn (AuditEvent $e) => json_encode($e->toArray()))->implode("\n");

    expect($serialized)->not->toContain($rawKey)
        ->not->toContain('$2y$');
});

it('records safe scope metadata on scope change audits', function () {
    [$merchant, $user] = uiKeyMember();
    $apiKey = createUiKey($merchant, ['scopes' => ['payments:read']]);

    actingAsUiKeyMember($merchant, $user)
        ->put(route('settings.api-keys.scopes', $apiKey), ['scopes' => ['refunds:read']]);

    $event = AuditEvent::where('event', AuditEventName::ApiKeyScopesUpdated->value)->firstOrFail();

    expect($event->metadata['old_scopes'])->toBe(['payments:read'])
        ->and($event->metadata['scopes'])->toBe(['refunds:read']);
});

// ---------------------------------------------------------------------------
// Regression
// ---------------------------------------------------------------------------

it('keeps key reads free of audit events', function () {
    [$merchant, $user] = uiKeyMember();
    $apiKey = createUiKey($merchant);
    $before = AuditEvent::count();

    actingAsUiKeyMember($merchant, $user)->get('/settings/api-keys')->assertOk();
    actingAsUiKeyMember($merchant, $user)->get(route('settings.api-keys.show', $apiKey))->assertOk();

    expect(AuditEvent::count())->toBe($before);
});

it('preserves the legacy create flow without optional fields', function () {
    [$merchant, $user] = uiKeyMember();

    actingAsUiKeyMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Legacy'])
        ->assertRedirect();

    $apiKey = $merchant->apiKeys()->firstOrFail();

    expect($apiKey->name)->toBe('Legacy')
        ->and(Hash::check(session('api_key_created')['raw'], $apiKey->key_hash))->toBeTrue()
        ->and($apiKey->scopes)->toEqual(ApiKeyScope::values());
});
