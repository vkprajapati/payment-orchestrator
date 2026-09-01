<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\ApiKeyScope;
use App\Enums\PaymentStatus;
use App\Models\ApiKey;
use App\Models\AuditEvent;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

/**
 * (apiKeyScope-prefixed helpers avoid clashing with sibling test files
 * under the same Pest process.)
 *
 * @param  list<string>|null  $scopes  null = full access default
 * @return array{0: Merchant, 1: string}
 */
function apiKeyScopeMerchant(string $name = 'Scope Merchant', ?array $scopes = null): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $rawKey = app(CreateApiKey::class)->create($merchant, 'CI/CD', null, null, $scopes)->rawKey;

    return [$merchant, $rawKey];
}

function apiKeyScopeAuth(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

// ---------------------------------------------------------------------------
// Scope validation at creation
// ---------------------------------------------------------------------------

it('defaults to full access when scopes are omitted', function () {
    [, $rawKey] = apiKeyScopeMerchant();

    $data = $this->postJson('/api/v1/api-keys', ['name' => 'Full Access'], apiKeyScopeAuth($rawKey))
        ->assertCreated()
        ->json('data');

    expect($data['scopes'])->toBe(ApiKeyScope::values());
});

it('accepts an explicit valid scope subset', function () {
    [, $rawKey] = apiKeyScopeMerchant();

    $data = $this->postJson('/api/v1/api-keys', [
        'name' => 'Read Only',
        'scopes' => ['payments:read', 'refunds:read'],
    ], apiKeyScopeAuth($rawKey))->assertCreated()->json('data');

    expect($data['scopes'])->toBe(['payments:read', 'refunds:read']);
});

it('rejects invalid, non-array, duplicate, and empty scopes', function (array $body, string $field) {
    [, $rawKey] = apiKeyScopeMerchant();

    $this->postJson('/api/v1/api-keys', $body, apiKeyScopeAuth($rawKey))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'invalid scope value' => [['name' => 'X', 'scopes' => ['payments:destroy']], 'scopes.0'],
    'non-array scopes' => [['name' => 'X', 'scopes' => 'payments:read'], 'scopes'],
    'duplicate scopes' => [['name' => 'X', 'scopes' => ['payments:read', 'payments:read']], 'scopes'],
    'empty scopes array' => [['name' => 'X', 'scopes' => []], 'scopes'],
]);

// ---------------------------------------------------------------------------
// Enforcement matrix
// ---------------------------------------------------------------------------

it('denies a payments:read key from creating payments with a generic 403', function () {
    [, $rawKey] = apiKeyScopeMerchant(scopes: [ApiKeyScope::PaymentsRead->value]);

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeyScopeAuth($rawKey))
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);
});

it('allows a payments:write key to create payments but not process them', function () {
    [, $rawKey] = apiKeyScopeMerchant(scopes: [
        ApiKeyScope::PaymentsWrite->value,
        ApiKeyScope::PaymentsRead->value,
    ]);

    $reference = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeyScopeAuth($rawKey))
        ->assertCreated()
        ->json('data.reference');

    // Write scope cannot process (payments:process required).
    $this->postJson("/api/v1/payments/{$reference}/process", [], apiKeyScopeAuth($rawKey))
        ->assertForbidden()
        ->assertJson(['message' => 'Forbidden.']);
});

it('allows a payments:process key to process but not create payments', function () {
    [$merchant, $fullKey] = apiKeyScopeMerchant();

    $reference = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeyScopeAuth($fullKey))
        ->assertCreated()
        ->json('data.reference');

    // Same merchant, restricted key.
    $processKey = app(CreateApiKey::class)->create($merchant, 'Processor', null, null, [
        ApiKeyScope::PaymentsProcess->value,
        ApiKeyScope::PaymentsRead->value,
    ])->rawKey;

    $this->postJson("/api/v1/payments/{$reference}/process", [], apiKeyScopeAuth($processKey))
        ->assertOk();

    // Process scope cannot create new payments.
    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeyScopeAuth($processKey))
        ->assertForbidden();
});

it('restricts api key management to api_keys scopes', function () {
    [, $readKey] = apiKeyScopeMerchant(scopes: [ApiKeyScope::ApiKeysRead->value]);
    [, $paymentsKey] = apiKeyScopeMerchant(scopes: [
        ApiKeyScope::PaymentsRead->value,
        ApiKeyScope::PaymentsWrite->value,
    ]);

    // api_keys:read can list.
    $this->getJson('/api/v1/api-keys', apiKeyScopeAuth($readKey))->assertOk();

    // ...but not create, revoke, or rotate.
    $this->postJson('/api/v1/api-keys', ['name' => 'Nope'], apiKeyScopeAuth($readKey))->assertForbidden();
    $this->postJson('/api/v1/api-keys/key_unknown/revoke', [], apiKeyScopeAuth($readKey))->assertForbidden();
    $this->postJson('/api/v1/api-keys/key_unknown/rotate', [], apiKeyScopeAuth($readKey))->assertForbidden();

    // A payments-scoped key cannot access key management at all.
    $this->getJson('/api/v1/api-keys', apiKeyScopeAuth($paymentsKey))->assertForbidden();
    $this->postJson('/api/v1/api-keys', ['name' => 'Nope'], apiKeyScopeAuth($paymentsKey))->assertForbidden();
});

it('restricts audit endpoints to audit:read which cannot mutate payments', function () {
    [, $auditKey] = apiKeyScopeMerchant(scopes: [ApiKeyScope::AuditRead->value]);

    $this->getJson('/api/v1/audit-events', apiKeyScopeAuth($auditKey))->assertOk();
    $this->getJson('/api/v1/audit-events/metrics', apiKeyScopeAuth($auditKey))->assertOk();

    // Audit scope cannot mutate payments.
    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeyScopeAuth($auditKey))
        ->assertForbidden();
});

it('requires account:read for the /me endpoint', function () {
    [, $paymentsKey] = apiKeyScopeMerchant(scopes: [ApiKeyScope::PaymentsRead->value]);
    [, $accountKey] = apiKeyScopeMerchant(scopes: [ApiKeyScope::AccountRead->value]);

    // A payments-only key cannot call /me (identity is a distinct scope).
    $this->getJson('/api/v1/me', apiKeyScopeAuth($paymentsKey))->assertForbidden();

    $this->getJson('/api/v1/me', apiKeyScopeAuth($accountKey))->assertOk();
});

it('supports refunds read and write scopes independently', function () {
    [$merchant, $fullKey] = apiKeyScopeMerchant();

    // Refunds require a succeeded payment with a successful attempt
    // (same setup convention as RefundApiTest).
    $payment = Payment::factory()->for($merchant)->create([
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
    ]);
    PaymentAttempt::factory()->forPayment($payment)->succeeded()->create([
        'provider' => 'mock',
        'provider_payment_id' => 'pi_test_scopes',
    ]);

    // Both scoped keys belong to the SAME merchant as the payment.
    $readKey = app(CreateApiKey::class)->create($merchant, 'Refund Reader', null, null, [
        ApiKeyScope::RefundsRead->value,
    ])->rawKey;
    $writeKey = app(CreateApiKey::class)->create($merchant, 'Refund Writer', null, null, [
        ApiKeyScope::RefundsWrite->value,
    ])->rawKey;

    // Write scope can create refunds but read scope cannot.
    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000], apiKeyScopeAuth($readKey))
        ->assertForbidden();

    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000], apiKeyScopeAuth($writeKey))
        ->assertCreated();

    // Read scope can list refunds.
    $this->getJson("/api/v1/payments/{$payment->reference}/refunds", apiKeyScopeAuth($readKey))->assertOk();
});

// ---------------------------------------------------------------------------
// Middleware ordering & failure semantics
// ---------------------------------------------------------------------------

it('returns generic 401 for invalid, revoked, and expired keys before scope checks', function () {
    [$merchant, $rawKey] = apiKeyScopeMerchant(scopes: [ApiKeyScope::PaymentsRead->value]);

    $invalidKey = CreateApiKey::KEY_PREFIX.str()->random(CreateApiKey::SECRET_LENGTH);

    // Invalid key → 401 (not 403).
    $this->postJson('/api/v1/payments', ['amount' => 1000], apiKeyScopeAuth($invalidKey))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);

    // Missing key → 401.
    $this->postJson('/api/v1/payments', ['amount' => 1000])->assertUnauthorized();

    // Revoked key → 401 even though it would hold the required scope.
    ApiKey::query()->where('merchant_id', $merchant->id)->update(['revoked_at' => now()]);
    $this->getJson('/api/v1/payments', apiKeyScopeAuth($rawKey))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);

    // Expired key → 401.
    ApiKey::query()->where('merchant_id', $merchant->id)->update([
        'revoked_at' => null,
        'expires_at' => now()->subMinute(),
    ]);
    $this->getJson('/api/v1/payments', apiKeyScopeAuth($rawKey))
        ->assertUnauthorized()
        ->assertJson(['message' => 'Invalid API key.']);
});

it('declares the scope middleware on representative routes in the right order', function () {
    // gatherMiddleware() prepends the global "api" group marker; the
    // route-level middleware follows in exact execution order.
    $stack = fn (string $name): array => collect(Route::getRoutes()->getByName($name)->gatherMiddleware())
        ->reject(fn ($m): bool => $m === 'api')
        ->values()
        ->all();

    // api.key authenticates first, then scope authorizes, then throttle.
    expect($stack('api.v1.payments.store'))->toBe(['api.key', 'scope:payments:write', 'throttle:sensitive'])
        ->and($stack('api.v1.payments.index'))->toBe(['api.key', 'scope:payments:read', 'throttle:standard'])
        ->and($stack('api.v1.payments.show'))->toBe(['api.key', 'scope:payments:read', 'throttle:standard'])
        ->and($stack('api.v1.payments.process'))->toBe(['api.key', 'scope:payments:process', 'throttle:sensitive'])
        ->and($stack('api.v1.payments.attempts.execute'))->toBe(['api.key', 'scope:payments:process', 'throttle:sensitive'])
        ->and($stack('api.v1.payments.refunds.store'))->toBe(['api.key', 'scope:refunds:write', 'throttle:sensitive'])
        ->and($stack('api.v1.payments.refunds.index'))->toBe(['api.key', 'scope:refunds:read', 'throttle:standard'])
        ->and($stack('api.v1.api-keys.index'))->toBe(['api.key', 'scope:api_keys:read', 'throttle:standard'])
        ->and($stack('api.v1.api-keys.revoke'))->toBe(['api.key', 'scope:api_keys:write', 'throttle:sensitive'])
        ->and($stack('api.v1.api-keys.rotate'))->toBe(['api.key', 'scope:api_keys:write', 'throttle:sensitive'])
        ->and($stack('api.v1.audit-events.index'))->toBe(['api.key', 'scope:audit:read', 'throttle:standard'])
        ->and($stack('api.v1.audit-events.export'))->toBe(['api.key', 'scope:audit:read', 'throttle:export'])
        ->and($stack('api.v1.me'))->toBe(['api.key', 'scope:account:read', 'throttle:standard']);

    // Webhooks remain outside api.key and scope enforcement entirely.
    $webhook = collect(Route::getRoutes()->getByName('api.v1.webhooks.handle')->gatherMiddleware());

    expect($webhook)->not->toContain('api.key')
        ->and($webhook->filter(fn ($m): bool => is_string($m) && str_starts_with((string) $m, 'scope:')))->isEmpty();
});

// ---------------------------------------------------------------------------
// Backwards compatibility
// ---------------------------------------------------------------------------

it('treats a legacy NULL scopes key as full access', function () {
    [$merchant, $rawKey] = apiKeyScopeMerchant();

    // Simulate a pre-scope row that escaped backfill.
    ApiKey::query()->where('merchant_id', $merchant->id)->update(['scopes' => null]);

    // Every domain remains reachable.
    $this->getJson('/api/v1/me', apiKeyScopeAuth($rawKey))->assertOk();
    $this->getJson('/api/v1/payments', apiKeyScopeAuth($rawKey))->assertOk();
    $this->getJson('/api/v1/audit-events', apiKeyScopeAuth($rawKey))->assertOk();
    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeyScopeAuth($rawKey))
        ->assertCreated();
});

it('resolves explicit full-scope backfill correctly', function () {
    [$merchant, $rawKey] = apiKeyScopeMerchant();

    // Mirror the production migration backfill state.
    ApiKey::query()->where('merchant_id', $merchant->id)
        ->update(['scopes' => json_encode(ApiKeyScope::values())]);

    $key = ApiKey::query()->where('merchant_id', $merchant->id)->first();

    expect($key->scopes)->toBe(ApiKeyScope::values())
        ->and($key->hasScope(ApiKeyScope::PaymentsWrite->value))->toBeTrue()
        ->and($key->hasScope(ApiKeyScope::AuditRead->value))->toBeTrue();

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeyScopeAuth($rawKey))
        ->assertCreated();
});

// ---------------------------------------------------------------------------
// Rotation inheritance
// ---------------------------------------------------------------------------

it('inherits exact scopes through rotation without escalation or drops', function () {
    [$merchant, $rawKey] = apiKeyScopeMerchant();

    $restricted = [ApiKeyScope::PaymentsRead->value, ApiKeyScope::RefundsRead->value];
    $old = app(CreateApiKey::class)->create($merchant, 'Restricted', null, null, $restricted);

    $data = $this->postJson('/api/v1/api-keys/'.$old->apiKey->reference.'/rotate', [], apiKeyScopeAuth($rawKey))
        ->assertCreated()
        ->json('data');

    expect($data['scopes'])->toBe($restricted);

    $replacement = ApiKey::query()->where('reference', $data['reference'])->first();

    expect($replacement->scopes)->toBe($restricted)
        ->and($replacement->hasScope(ApiKeyScope::PaymentsWrite->value))->toBeFalse()
        ->and($replacement->hasScope(ApiKeyScope::ApiKeysWrite->value))->toBeFalse();
});

it('preserves scopes in an idempotent rotation replay', function () {
    [$merchant, $rawKey] = apiKeyScopeMerchant();

    $restricted = [ApiKeyScope::AuditRead->value];
    $old = app(CreateApiKey::class)->create($merchant, 'Auditor', null, null, $restricted);
    $headers = ['Idempotency-Key' => 'scope-rotation-replay'];

    $first = $this->postJson('/api/v1/api-keys/'.$old->apiKey->reference.'/rotate', [], apiKeyScopeAuth($rawKey) + $headers)
        ->assertCreated();
    $second = $this->postJson('/api/v1/api-keys/'.$old->apiKey->reference.'/rotate', [], apiKeyScopeAuth($rawKey) + $headers)
        ->assertCreated();

    expect($second->json('data.reference'))->toBe($first->json('data.reference'))
        ->and($second->json('data.scopes'))->toBe($restricted)
        ->and(ApiKey::query()->where('name', 'Auditor')->count())->toBe(2); // old + one replacement
});

// ---------------------------------------------------------------------------
// Isolation & security
// ---------------------------------------------------------------------------

it('never lets scopes override merchant isolation', function () {
    [$merchantA, $keyA] = apiKeyScopeMerchant('Merchant A'); // full access
    [$merchantB] = apiKeyScopeMerchant('Merchant B');

    $bPayment = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeyScopeAuth(app(CreateApiKey::class)->create($merchantB, 'b')->rawKey))
        ->assertCreated()
        ->json('data.reference');

    // Full-scope key from merchant A cannot read merchant B's payment.
    $this->getJson("/api/v1/payments/{$bPayment}", apiKeyScopeAuth($keyA))
        ->assertNotFound()
        ->assertJson(['message' => 'Not found.']);

    // ...and cannot see merchant B's API keys.
    $aList = $this->getJson('/api/v1/api-keys', apiKeyScopeAuth($keyA))->json('data');

    expect(collect($aList))->toHaveCount(1); // only merchant A's own key
});

it('creates zero audit rows for scope-denied requests', function () {
    [, $readKey] = apiKeyScopeMerchant(scopes: [ApiKeyScope::PaymentsRead->value]);

    $before = AuditEvent::count();

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], apiKeyScopeAuth($readKey))
        ->assertForbidden();

    expect(AuditEvent::count())->toBe($before);
});
