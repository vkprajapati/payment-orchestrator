<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant with a real API key, returning the raw key.
 */
function rlMerchant(string $name = 'RateLimit Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

function rlAuth(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

/**
 * A succeeded payment (with successful mock attempt) owned by the merchant.
 */
function rlSucceededPayment(Merchant $merchant, int $amount = 10000): Payment
{
    $payment = Payment::factory()->for($merchant)->create([
        'amount' => $amount,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
    ]);

    PaymentAttempt::factory()->forPayment($payment)->succeeded()->create([
        'provider' => 'mock',
        'provider_payment_id' => 'pi_rl_1',
    ]);

    return $payment;
}

/**
 * Clear the rate-limit cache store so each test starts fresh.
 */
function clearRateLimits(): void
{
    Cache::flush();
}

beforeEach(function () {
    clearRateLimits();
});

// ---------------------------------------------------------------------------
// Authentication / isolation
// ---------------------------------------------------------------------------

it('allows a valid authenticated request below the limit', function () {
    [$merchant, $rawKey] = rlMerchant();
    rlSucceededPayment($merchant);

    $response = $this->getJson('/api/v1/payments', rlAuth($rawKey));

    $response->assertOk();
    expect($response->status())->toBe(200);
});

it('does not block a second merchant when the first exhausts its limit', function () {
    [$merchantA, $keyA] = rlMerchant('Merchant A');
    [$merchantB, $keyB] = rlMerchant('Merchant B');

    // Exhaust Merchant A's sensitive bucket (60 attempts)
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($keyA) + ['Idempotency-Key' => 'limit-'.$i]);
    }

    // Merchant B should still be able to create payments
    $responseB = $this->postJson('/api/v1/payments', ['amount' => 2000, 'currency' => 'USD'], rlAuth($keyB) + ['Idempotency-Key' => 'other-merchant']);
    $responseB->assertCreated();

    // Merchant A should now be rate-limited
    $responseA = $this->postJson('/api/v1/payments', ['amount' => 3000, 'currency' => 'USD'], rlAuth($keyA) + ['Idempotency-Key' => 'limit-final']);
    $responseA->assertStatus(429);
});

it('does not let an invalid API key consume a valid merchant bucket', function () {
    [$merchant, $rawKey] = rlMerchant();

    // Make many requests with an invalid key — should all get 401, not 429
    for ($i = 0; $i < 150; $i++) {
        $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], ['Authorization' => 'Bearer sk_invalid_key_12345']);
        $response->assertStatus(401);
    }

    // Valid merchant should still be unaffected
    $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'valid-after-invalid']);
    $response->assertCreated();
});

it('preserves existing authentication behavior when no API key is sent', function () {
    $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD']);

    $response->assertStatus(401);
});

// ---------------------------------------------------------------------------
// Standard API limits
// ---------------------------------------------------------------------------

it('allows requests below the standard limit to succeed', function () {
    [$merchant, $rawKey] = rlMerchant();
    rlSucceededPayment($merchant);

    $response = $this->getJson('/api/v1/payments', rlAuth($rawKey));
    $response->assertOk();
});

it('returns 429 when exceeding the standard limit', function () {
    [$merchant, $rawKey] = rlMerchant();
    rlSucceededPayment($merchant);

    // Exhaust the standard bucket (1200 attempts via GET)
    for ($i = 0; $i < 1200; $i++) {
        $this->getJson('/api/v1/payments', rlAuth($rawKey));
    }

    $response = $this->getJson('/api/v1/payments', rlAuth($rawKey));
    $response->assertStatus(429);
});

it('rate-limited response does not expose internal information', function () {
    [$merchant, $rawKey] = rlMerchant();
    rlSucceededPayment($merchant);

    for ($i = 0; $i < 1200; $i++) {
        $this->getJson('/api/v1/payments', rlAuth($rawKey));
    }

    $response = $this->getJson('/api/v1/payments', rlAuth($rawKey));
    $body = json_encode($response->json());

    expect($body)->not->toContain('merchant:'.$merchant->id)
        ->and($body)->not->toContain('api:merchant')
        ->and($body)->not->toContain('request_hash')
        ->and($response->getContent())->not->toContain($rawKey);
});

it('includes retry and rate-limit headers when rate limited', function () {
    [$merchant, $rawKey] = rlMerchant();
    rlSucceededPayment($merchant);

    for ($i = 0; $i < 1200; $i++) {
        $this->getJson('/api/v1/payments', rlAuth($rawKey));
    }

    $response = $this->getJson('/api/v1/payments', rlAuth($rawKey));
    $response->assertStatus(429);
    expect($response->headers->has('Retry-After'))->toBeTrue();
    expect($response->headers->has('X-RateLimit-Remaining'))->toBeTrue();
    expect($response->headers->get('X-RateLimit-Remaining'))->toBe('0');
});

// ---------------------------------------------------------------------------
// Sensitive operations bucket
// ---------------------------------------------------------------------------

it('uses the sensitive bucket for payment creation', function () {
    [$merchant, $rawKey] = rlMerchant();

    // Exhaust sensitive bucket (60 attempts)
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'sens-'.$i]);
    }

    // Should be rate-limited now
    $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'sens-final']);
    $response->assertStatus(429);

    // But standard bucket should still work (GET payments)
    $getResponse = $this->getJson('/api/v1/payments', rlAuth($rawKey));
    $getResponse->assertOk();
});

it('uses the sensitive bucket for payment processing', function () {
    [$merchant, $rawKey] = rlMerchant();
    $payment = rlSucceededPayment($merchant);

    // Exhaust sensitive bucket
    for ($i = 0; $i < 60; $i++) {
        $this->postJson("/api/v1/payments/{$payment->reference}/process", [], rlAuth($rawKey));
    }

    // Process should be rate-limited
    $response = $this->postJson("/api/v1/payments/{$payment->reference}/process", [], rlAuth($rawKey));
    $response->assertStatus(429);

    // Standard bucket still works
    $getResponse = $this->getJson('/api/v1/payments', rlAuth($rawKey));
    $getResponse->assertOk();
});

it('uses the sensitive bucket for refund creation', function () {
    [$merchant, $rawKey] = rlMerchant();
    $payment = rlSucceededPayment($merchant);

    // Exhaust sensitive bucket
    for ($i = 0; $i < 60; $i++) {
        $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 100, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 's-refund-'.$i]);
    }

    // Refund should be rate-limited
    $response = $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 100, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 's-refund-final']);
    $response->assertStatus(429);

    // Standard bucket still works
    $getResponse = $this->getJson('/api/v1/payments', rlAuth($rawKey));
    $getResponse->assertOk();
});

// ---------------------------------------------------------------------------
// Endpoint separation
// ---------------------------------------------------------------------------

it('exhausting the payment-listing bucket does not block payment processing', function () {
    [$merchant, $rawKey] = rlMerchant();
    $payment = rlSucceededPayment($merchant);

    // Exhaust standard bucket via GET
    for ($i = 0; $i < 1200; $i++) {
        $this->getJson('/api/v1/payments', rlAuth($rawKey));
    }

    // GET should be rate-limited
    $getResponse = $this->getJson('/api/v1/payments', rlAuth($rawKey));
    $getResponse->assertStatus(429);

    // POST (sensitive) should still work — different bucket
    $postResponse = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'post-after-list']);
    $postResponse->assertCreated();
});

it('exhausting refund listing does not block payment processing', function () {
    [$merchant, $rawKey] = rlMerchant();
    $payment = rlSucceededPayment($merchant);

    // Exhaust standard bucket via GET refunds
    for ($i = 0; $i < 1200; $i++) {
        $this->getJson("/api/v1/payments/{$payment->reference}/refunds", rlAuth($rawKey));
    }

    // GET refunds should be rate-limited
    $getResponse = $this->getJson("/api/v1/payments/{$payment->reference}/refunds", rlAuth($rawKey));
    $getResponse->assertStatus(429);

    // POST payment (sensitive) should still work
    $postResponse = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'post-after-refund-list']);
    $postResponse->assertCreated();
});

it('exhausting the sensitive bucket does not block refund listing', function () {
    [$merchant, $rawKey] = rlMerchant();
    $payment = rlSucceededPayment($merchant);

    // Exhaust sensitive bucket via POST
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'sens-indep-'.$i]);
    }

    // POST should be rate-limited
    $postResponse = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'sens-indep-final']);
    $postResponse->assertStatus(429);

    // GET refunds (standard) should still work
    $getResponse = $this->getJson("/api/v1/payments/{$payment->reference}/refunds", rlAuth($rawKey));
    $getResponse->assertOk();
});

// ---------------------------------------------------------------------------
// Idempotency regression
// ---------------------------------------------------------------------------

it('allows idempotent payment creation below rate limits', function () {
    [$merchant, $rawKey] = rlMerchant();

    $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'idem-create-ok']);
    $response->assertCreated();
});

it('replays an idempotent payment retry without reexecuting the domain operation', function () {
    [$merchant, $rawKey] = rlMerchant();

    $payload = ['amount' => 1050, 'currency' => 'USD'];
    $headers = rlAuth($rawKey) + ['Idempotency-Key' => 'idem-replay'];

    $first = $this->postJson('/api/v1/payments', $payload, $headers);
    $second = $this->postJson('/api/v1/payments', $payload, $headers);

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->getContent())->toBe($first->getContent());
    expect(Payment::count())->toBe(1);
});

it('does not corrupt idempotency storage under rate limiting', function () {
    [$merchant, $rawKey] = rlMerchant();

    // Make some requests that consume rate-limit budget
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'budget-'.$i]);
    }

    // Same idempotency key retry should still work and replay correctly
    $payload = ['amount' => 2000, 'currency' => 'USD'];
    $headers = rlAuth($rawKey) + ['Idempotency-Key' => 'budget-replay'];

    $first = $this->postJson('/api/v1/payments', $payload, $headers);
    $second = $this->postJson('/api/v1/payments', $payload, $headers);

    $first->assertCreated();
    $second->assertCreated();
    expect($second->getContent())->toBe($first->getContent());
    expect(Payment::count())->toBe(6); // 5 unique + 1 replay
});

it('preserves idempotency conflict behavior for different payloads under rate limits', function () {
    [$merchant, $rawKey] = rlMerchant();

    $headers = rlAuth($rawKey) + ['Idempotency-Key' => 'conflict-under-limits'];

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], $headers)->assertCreated();
    $conflict = $this->postJson('/api/v1/payments', ['amount' => 2000, 'currency' => 'USD'], $headers);
    $conflict->assertStatus(409);
});

it('allows idempotent refund creation below rate limits', function () {
    [$merchant, $rawKey] = rlMerchant();
    $payment = rlSucceededPayment($merchant);

    $response = $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'idem-refund-ok']);
    $response->assertCreated();
});

it('replays an idempotent refund retry without reexecuting the domain operation', function () {
    [$merchant, $rawKey] = rlMerchant();
    $payment = rlSucceededPayment($merchant);

    $payload = ['amount' => 500, 'currency' => 'USD'];
    $headers = rlAuth($rawKey) + ['Idempotency-Key' => 'idem-refund-replay'];
    $url = "/api/v1/payments/{$payment->reference}/refunds";

    $first = $this->postJson($url, $payload, $headers);
    $second = $this->postJson($url, $payload, $headers);

    $first->assertCreated();
    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->getContent())->toBe($first->getContent());
    expect(Refund::count())->toBe(1);
});

it('does not let rate limiting bypass authentication', function () {
    // No API key — should get 401, not 429
    $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD']);
    $response->assertStatus(401);
});

it('keeps idempotency and rate limiting buckets independent per merchant', function () {
    [$merchantA, $keyA] = rlMerchant('Merchant A');
    [$merchantB, $keyB] = rlMerchant('Merchant B');

    // Merchant A exhausts sensitive bucket
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($keyA) + ['Idempotency-Key' => 'ind-'.$i]);
    }

    // Merchant A is rate-limited for POST
    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($keyA) + ['Idempotency-Key' => 'ind-final-a'])->assertStatus(429);

    // Merchant B creates a payment with the SAME idempotency key — must succeed
    $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($keyB) + ['Idempotency-Key' => 'ind-replay']);
    $response->assertCreated();
});

// ---------------------------------------------------------------------------
// Webhook regression
// ---------------------------------------------------------------------------

it('does not rate-limit webhook requests', function () {
    $route = Route::getRoutes()->getByName('api.v1.webhooks.handle');
    expect($route)->not->toBeNull();

    // Webhook routes are outside api.key and throttle — make many calls
    for ($i = 0; $i < 10; $i++) {
        $response = $this->postJson('/api/v1/webhooks/mock', [
            'provider_payment_id' => 'pi_webhook_test_'.$i,
            'event' => 'payment.succeeded',
            'status' => 'succeeded',
        ]);
        expect($response->status())->not->toBe(429);
    }
});

it('rejects an invalid-signature webhook without rate limiting interference', function () {
    $response = $this->postJson('/api/v1/webhooks/mock', [
        'event_type' => 'payment.succeeded',
        'provider' => 'mock',
    ], ['Idempotency-Key' => 'should-not-matter']);

    $response->assertStatus(400);
});

it('preserves existing valid webhook behavior', function () {
    [$merchant, $rawKey] = rlMerchant();
    rlSucceededPayment($merchant);

    $response = $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'pi_rl_1',
        'event' => 'payment.succeeded',
        'status' => 'succeeded',
    ]);

    $response->assertOk();
    $response->assertJson(['received' => true]);
});

// ---------------------------------------------------------------------------
// Security
// ---------------------------------------------------------------------------

it('does not let request-supplied merchant_id control the limiter bucket', function () {
    [$merchantA, $keyA] = rlMerchant('Merchant A');
    [$merchantB, $keyB] = rlMerchant('Merchant B');

    // Exhaust Merchant A's bucket
    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD', 'merchant_id' => $merchantB->id], rlAuth($keyA) + ['Idempotency-Key' => 'sec-bucket-'.$i]);
    }

    // Merchant A rate-limited
    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD', 'merchant_id' => $merchantB->id], rlAuth($keyA) + ['Idempotency-Key' => 'sec-bucket-final'])->assertStatus(429);

    // Merchant B still works, even with merchant_id=A in body
    $response = $this->postJson('/api/v1/payments', ['amount' => 2000, 'currency' => 'USD', 'merchant_id' => $merchantA->id], rlAuth($keyB) + ['Idempotency-Key' => 'sec-bucket-b']);
    $response->assertCreated();
});

it('never exposes API keys in rate-limit failure responses', function () {
    [$merchant, $rawKey] = rlMerchant();

    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'leak-'.$i]);
    }

    $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($rawKey) + ['Idempotency-Key' => 'leak-final']);
    $response->assertStatus(429);

    $body = $response->getContent();
    expect($body)->not->toContain($rawKey)
        ->and($body)->not->toContain('Bearer ')
        ->and($body)->not->toContain('api_key')
        ->and($body)->not->toContain('secret');
});

it('prevents cross-merchant rate-limit state inference', function () {
    [$merchantA, $keyA] = rlMerchant('Merchant A');
    [$merchantB, $keyB] = rlMerchant('Merchant B');

    for ($i = 0; $i < 60; $i++) {
        $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($keyA) + ['Idempotency-Key' => 'cross-'.$i]);
    }

    // Merchant A gets 429
    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($keyA) + ['Idempotency-Key' => 'cross-final-a'])->assertStatus(429);

    // Merchant B must not get 429
    $response = $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], rlAuth($keyB) + ['Idempotency-Key' => 'cross-final-b']);
    expect($response->status())->not->toBe(429);
});
