<?php

use App\Models\Merchant;
use App\Models\User;
use App\Services\Merchants\CurrentMerchant;
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
function apiKeyMember(string $role = 'owner'): array
{
    $merchant = Merchant::factory()->create(['name' => 'Acme Inc.', 'slug' => 'acme-inc']);
    $user = User::factory()->create();
    $user->merchants()->attach($merchant, ['role' => $role]);

    return [$merchant, $user];
}

/**
 * Act as the given member with the merchant set as the current context.
 */
function actingAsMerchantMember(Merchant $merchant, User $user): TestCase
{
    return test()->withSession([CurrentMerchant::SESSION_KEY => $merchant->id])
        ->actingAs($user);
}

it('allows managing roles to view API keys', function (string $role) {
    [$merchant, $user] = apiKeyMember($role);

    actingAsMerchantMember($merchant, $user)
        ->get('/settings/api-keys')
        ->assertOk()
        ->assertSee('API Keys');
})->with(['owner', 'admin', 'developer']);

it('forbids a viewer from accessing API key management', function () {
    [$merchant, $user] = apiKeyMember('viewer');

    actingAsMerchantMember($merchant, $user)
        ->get('/settings/api-keys')
        ->assertForbidden();
});

it('rejects a viewer attempt to create an API key', function () {
    [$merchant, $user] = apiKeyMember('viewer');

    actingAsMerchantMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Forbidden Key'])
        ->assertForbidden();

    $this->assertDatabaseCount('api_keys', 0);
});

it('creates an API key with a hashed secret', function () {
    [$merchant, $user] = apiKeyMember('owner');

    actingAsMerchantMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Production Server'])
        ->assertRedirect();

    $apiKey = $merchant->apiKeys()->firstOrFail();

    expect($apiKey->name)->toBe('Production Server')
        ->and($apiKey->merchant_id)->toBe($merchant->id)
        ->and($apiKey->key_hash)->not->toBeEmpty()
        ->and($apiKey->key_hash)->not->toContain('sk_test_');
});

it('generates keys with the sk_test_ prefix', function () {
    [$merchant, $user] = apiKeyMember('owner');

    actingAsMerchantMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Development']);

    $apiKey = $merchant->apiKeys()->firstOrFail();

    expect($apiKey->key_prefix)->toStartWith('sk_test_');
});

it('verifies the raw key against the stored hash', function () {
    [$merchant, $user] = apiKeyMember('owner');

    actingAsMerchantMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'CI/CD']);

    $apiKey = $merchant->apiKeys()->firstOrFail();
    $rawKey = session('api_key_created')['raw'];

    expect(Hash::check($rawKey, $apiKey->key_hash))->toBeTrue()
        ->and(Str::startsWith($rawKey, 'sk_test_'))->toBeTrue();
});

it('displays the raw key once and never again', function () {
    [$merchant, $user] = apiKeyMember('owner');

    actingAsMerchantMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Mobile Application']);

    $apiKey = $merchant->apiKeys()->firstOrFail();
    $rawKey = session('api_key_created')['raw'];

    // The raw key is displayed exactly once.
    actingAsMerchantMember($merchant, $user)
        ->get(route('settings.api-keys.created', $apiKey))
        ->assertOk()
        ->assertSee($rawKey);

    // After the one-time display the session entry is consumed.
    expect(session('api_key_created'))->toBeNull();

    // Refreshing the page no longer exposes the key.
    actingAsMerchantMember($merchant, $user)
        ->get(route('settings.api-keys.created', $apiKey))
        ->assertRedirect(route('settings.api-keys.index'));

    // The raw key is not stored in the database.
    $this->assertDatabaseMissing('api_keys', ['key_hash' => $rawKey]);
});

it('rejects invalid API key names', function (?string $name) {
    [$merchant, $user] = apiKeyMember('owner');

    actingAsMerchantMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => $name])
        ->assertSessionHasErrors('name');

    $this->assertDatabaseCount('api_keys', 0);
})->with([null, '', '   ']);

it('associates the API key with its merchant', function () {
    [$merchant, $user] = apiKeyMember('owner');

    actingAsMerchantMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Production Server']);

    $apiKey = $merchant->apiKeys()->firstOrFail();

    expect($merchant->apiKeys()->get())->toHaveCount(1)
        ->and($apiKey->merchant->is($merchant))->toBeTrue()
        ->and($merchant->apiKeys()->first()->is($apiKey))->toBeTrue();
});

it('blocks cross-merchant API key revocation', function () {
    [$merchantA, $userA] = apiKeyMember('owner');
    $merchantB = Merchant::factory()->create(['name' => 'Other Co.', 'slug' => 'other-co']);
    $foreignKey = $merchantB->apiKeys()->create([
        'name' => 'Foreign Key',
        'key_prefix' => 'sk_test_foreign',
        'key_hash' => Hash::make('sk_test_foreignsecret'),
    ]);

    actingAsMerchantMember($merchantA, $userA)
        ->delete(route('settings.api-keys.destroy', $foreignKey))
        ->assertNotFound();

    $this->assertDatabaseHas('api_keys', [
        'id' => $foreignKey->id,
        'revoked_at' => null,
    ]);
});

it('allows managing roles to revoke API keys', function (string $role) {
    [$merchant, $user] = apiKeyMember($role);
    $apiKey = $merchant->apiKeys()->create([
        'name' => 'Production Server',
        'key_prefix' => 'sk_test_ab12cd34',
        'key_hash' => Hash::make('sk_test_secret'),
    ]);

    actingAsMerchantMember($merchant, $user)
        ->delete(route('settings.api-keys.destroy', $apiKey))
        ->assertRedirect(route('settings.api-keys.index'))
        ->assertSessionHas('status');

    $this->assertDatabaseHas('api_keys', [
        'id' => $apiKey->id,
        'name' => 'Production Server',
    ]);

    expect($apiKey->fresh()->isRevoked())->toBeTrue();
})->with(['owner', 'admin', 'developer']);

it('forbids a viewer from revoking API keys', function () {
    [$merchant, $user] = apiKeyMember('viewer');
    $apiKey = $merchant->apiKeys()->create([
        'name' => 'Production Server',
        'key_prefix' => 'sk_test_ab12cd34',
        'key_hash' => Hash::make('sk_test_secret'),
    ]);

    actingAsMerchantMember($merchant, $user)
        ->delete(route('settings.api-keys.destroy', $apiKey))
        ->assertForbidden();

    expect($apiKey->fresh()->isRevoked())->toBeFalse();
});

it('keeps revoked keys in the database for audit history', function () {
    [$merchant, $user] = apiKeyMember('owner');
    $apiKey = $merchant->apiKeys()->create([
        'name' => 'Production Server',
        'key_prefix' => 'sk_test_ab12cd34',
        'key_hash' => Hash::make('sk_test_secret'),
    ]);

    actingAsMerchantMember($merchant, $user)
        ->delete(route('settings.api-keys.destroy', $apiKey));

    $this->assertDatabaseCount('api_keys', 1);
    $this->assertDatabaseHas('api_keys', [
        'id' => $apiKey->id,
        'name' => 'Production Server',
    ]);
});

it('is idempotent when revoking an already revoked key', function () {
    [$merchant, $user] = apiKeyMember('owner');
    $apiKey = $merchant->apiKeys()->create([
        'name' => 'Production Server',
        'key_prefix' => 'sk_test_ab12cd34',
        'key_hash' => Hash::make('sk_test_secret'),
    ]);

    actingAsMerchantMember($merchant, $user)
        ->delete(route('settings.api-keys.destroy', $apiKey));

    $revokedAt = $apiKey->fresh()->revoked_at;

    actingAsMerchantMember($merchant, $user)
        ->delete(route('settings.api-keys.destroy', $apiKey))
        ->assertRedirect(route('settings.api-keys.index'));

    expect($apiKey->fresh()->revoked_at->equalTo($revokedAt))->toBeTrue();
});

it('never exposes the raw key or hash in the listing', function () {
    [$merchant, $user] = apiKeyMember('owner');

    actingAsMerchantMember($merchant, $user)
        ->post('/settings/api-keys', ['name' => 'Production Server']);

    $apiKey = $merchant->apiKeys()->firstOrFail();
    $rawKey = session('api_key_created')['raw'];

    actingAsMerchantMember($merchant, $user)
        ->get('/settings/api-keys')
        ->assertOk()
        ->assertSee($apiKey->key_prefix)
        ->assertDontSee($rawKey)
        ->assertDontSee($apiKey->key_hash);
});
