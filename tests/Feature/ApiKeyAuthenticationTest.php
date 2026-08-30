<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Models\ApiKey;
use App\Models\Merchant;
use App\Services\ApiKeys\ApiRequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant to authenticate against.
 */
function apiAuthMerchant(string $name = 'Acme Payments'): Merchant
{
    return Merchant::factory()->create(['name' => $name]);
}

/**
 * Create a real API key via the production action and return
 * [ApiKey, rawKey] so tests can authenticate with the known secret.
 *
 * @return array{0: ApiKey, 1: string}
 */
function apiAuthKey(Merchant $merchant, string $name = 'CI/CD'): array
{
    $created = app(CreateApiKey::class)->create($merchant, $name);

    return [$created->apiKey, $created->rawKey];
}

it('authenticates a valid API key and returns its merchant', function () {
    $merchant = apiAuthMerchant();
    [, $rawKey] = apiAuthKey($merchant);

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertOk()
        ->assertJson([
            'merchant' => [
                'id' => $merchant->id,
                'name' => 'Acme Payments',
                'slug' => $merchant->slug,
            ],
        ]);
});

it('rejects a request without an authorization header', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects a well-formed key that does not exist', function () {
    $this->getJson('/api/v1/me', [
        'Authorization' => 'Bearer '.CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH),
    ])->assertUnauthorized();
});

it('rejects tokens with a wrong prefix', function () {
    $this->getJson('/api/v1/me', [
        'Authorization' => 'Bearer pk_test_'.Str::random(CreateApiKey::SECRET_LENGTH),
    ])->assertUnauthorized();
});

it('rejects malformed bearer tokens', function (string $token) {
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])
        ->assertUnauthorized();
})->with([
    'garbage' => 'abc',
    'prefix only' => CreateApiKey::KEY_PREFIX,
    'unstructured' => 'random-token',
    'too short' => CreateApiKey::KEY_PREFIX.Str::random(10),
    'invalid characters' => CreateApiKey::KEY_PREFIX.str_repeat('!', CreateApiKey::SECRET_LENGTH),
]);

it('rejects a revoked API key even when the secret matches', function () {
    $merchant = apiAuthMerchant();
    [$apiKey, $rawKey] = apiAuthKey($merchant);
    $apiKey->forceFill(['revoked_at' => now()])->save();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertUnauthorized();
});

it('rejects an expired API key', function () {
    $merchant = apiAuthMerchant();
    $rawKey = CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH);

    ApiKey::factory()->withRawKey($rawKey)->expired()->create([
        'merchant_id' => $merchant->id,
        'name' => 'Expired Key',
    ]);

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertUnauthorized();
});

it('accepts a key that expires in the future', function () {
    $merchant = apiAuthMerchant();
    $rawKey = CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH);

    ApiKey::factory()->withRawKey($rawKey)->create([
        'merchant_id' => $merchant->id,
        'expires_at' => now()->addDay(),
    ]);

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertOk();
});

it('rejects a key with the same prefix but a different secret', function () {
    $merchant = apiAuthMerchant();
    [, $rawKey] = apiAuthKey($merchant);

    // Same stored prefix, different secret: prefix lookup finds the
    // candidate, but Hash::check() must fail.
    $secretPortionOfPrefix = CreateApiKey::STORED_PREFIX_LENGTH - strlen(CreateApiKey::KEY_PREFIX);
    $attackKey = substr($rawKey, 0, CreateApiKey::STORED_PREFIX_LENGTH)
        .Str::random(CreateApiKey::SECRET_LENGTH - $secretPortionOfPrefix);

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$attackKey}"])
        ->assertUnauthorized();
});

it('records last_used_at after successful authentication', function () {
    $merchant = apiAuthMerchant();
    [$apiKey, $rawKey] = apiAuthKey($merchant);

    expect($apiKey->last_used_at)->toBeNull();

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertOk();

    expect($apiKey->fresh()->last_used_at)->not->toBeNull();
});

it('does not record last_used_at for failed authentication', function (string $mode) {
    $merchant = apiAuthMerchant();
    [$apiKey, $rawKey] = apiAuthKey($merchant);

    match ($mode) {
        'unknown secret' => $apiKey->forceFill([
            'key_hash' => Hash::make(CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH)),
        ])->save(),
        'revoked' => $apiKey->forceFill(['revoked_at' => now()])->save(),
        'expired' => $apiKey->forceFill(['expires_at' => now()->subMinute()])->save(),
    };

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertUnauthorized();

    expect($apiKey->fresh()->last_used_at)->toBeNull();
})->with(['unknown secret', 'revoked', 'expired']);

it('only exposes the merchant that owns the authenticated API key', function () {
    $merchantA = apiAuthMerchant('Merchant A');
    $merchantB = apiAuthMerchant('Merchant B');
    [, $rawKey] = apiAuthKey($merchantA);

    $response = $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertOk();

    expect($response->json('merchant.id'))->toBe($merchantA->id)
        ->and($response->json('merchant.name'))->toBe('Merchant A')
        ->and($response->json('merchant.id'))->not->toBe($merchantB->id);
});

it('populates the API request context after authentication', function () {
    $merchant = apiAuthMerchant();
    [$apiKey, $rawKey] = apiAuthKey($merchant);

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertOk();

    $context = app(ApiRequestContext::class);

    expect($context->has())->toBeTrue()
        ->and($context->apiKey()?->id)->toBe($apiKey->id)
        ->and($context->merchant()?->id)->toBe($merchant->id);
});

it('does not retain context once scoped bindings are flushed between requests', function () {
    $merchant = apiAuthMerchant();
    [$apiKey, $rawKey] = apiAuthKey($merchant);

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertOk();

    expect(app(ApiRequestContext::class)->has())->toBeTrue();

    // Octane and queue workers call forgetScopedInstances() between
    // requests/jobs. Simulating that exact lifecycle proves no merchant
    // or API key data leaks into the next request.
    $this->app->forgetScopedInstances();

    $context = app(ApiRequestContext::class);

    expect($context->has())->toBeFalse()
        ->and($context->apiKey())->toBeNull()
        ->and($context->merchant())->toBeNull();
});

it('returns an identical generic 401 body for every failure mode', function () {
    $merchant = apiAuthMerchant();
    [$apiKey, $rawKey] = apiAuthKey($merchant);

    $failures = [
        'missing header' => fn () => $this->getJson('/api/v1/me'),
        'malformed token' => fn () => $this->getJson('/api/v1/me', ['Authorization' => 'Bearer abc']),
        'wrong prefix' => fn () => $this->getJson('/api/v1/me', ['Authorization' => 'Bearer pk_test_'.Str::random(40)]),
        'unknown key' => fn () => $this->getJson('/api/v1/me', [
            'Authorization' => 'Bearer '.CreateApiKey::KEY_PREFIX.Str::random(CreateApiKey::SECRET_LENGTH),
        ]),
        'revoked key' => function () use ($apiKey, $rawKey) {
            $apiKey->forceFill(['revoked_at' => now()])->save();

            return $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"]);
        },
        'expired key' => function () use ($apiKey, $rawKey) {
            $apiKey->forceFill(['expires_at' => now()->subMinute()])->save();

            return $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"]);
        },
    ];

    foreach ($failures as $mode => $request) {
        $response = $request();

        expect($response->status())->toBe(401, "Failure mode [{$mode}] did not return 401.")
            ->and($response->getContent())->toBe('{"message":"Invalid API key."}');
    }
});

it('does not leak the key hash or raw key in the /api/v1/me response', function () {
    $merchant = apiAuthMerchant();
    [$apiKey, $rawKey] = apiAuthKey($merchant);

    $response = $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$rawKey}"])
        ->assertOk();

    expect($response->getContent())->not->toContain($rawKey)
        ->and($response->getContent())->not->toContain($apiKey->key_hash)
        ->and($response->getContent())->not->toContain('key_hash');
});
