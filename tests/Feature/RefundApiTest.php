<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Actions\Payments\ProcessRefund;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\RefundNotProcessableException;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant with a real API key, returning the raw key.
 *
 * @return array{0: Merchant, 1: string}
 */
function refundApiMerchant(string $name = 'Refund Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

/**
 * A succeeded payment owned by the merchant, with one successful attempt
 * (the provider the refund will be routed through).
 *
 * @return array{0: Payment, 1: PaymentAttempt}
 */
function succeededPaymentWithAttempt(Merchant $merchant, int $amount = 10000, string $provider = 'mock'): array
{
    $payment = Payment::factory()->for($merchant)->create([
        'amount' => $amount,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
    ]);

    $attempt = PaymentAttempt::factory()->forPayment($payment)->succeeded()->create([
        'provider' => $provider,
        'provider_payment_id' => 'pi_test_123',
    ]);

    return [$payment, $attempt];
}

function postRefund(string $reference, string $rawKey, array $payload = []): TestResponse
{
    return test()->postJson("/api/v1/payments/{$reference}/refunds", $payload, [
        'Authorization' => "Bearer {$rawKey}",
    ]);
}

// ---------------------------------------------------------------------------
// Authentication and merchant isolation
// ---------------------------------------------------------------------------

it('creates and executes a refund for the authenticated merchant', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    postRefund($payment->reference, $rawKey, ['amount' => 3000, 'reason' => 'Customer request'])
        ->assertCreated()
        ->assertJsonPath('data.status', RefundStatus::Succeeded->value)
        ->assertJsonPath('data.provider', 'mock')
        ->assertJsonPath('data.amount', 3000)
        ->assertJsonPath('data.currency', 'USD')
        ->assertJsonPath('data.payment_reference', $payment->reference)
        ->assertJsonPath('data.reason', 'Customer request')
        ->assertJsonPath('data.failure_code', null)
        ->assertJsonStructure(['data' => ['provider_refund_id', 'completed_at', 'requested_at']]);

    $refund = Refund::query()->sole();

    expect($refund->provider_refund_id)->toStartWith('mock_refund_')
        ->and($refund->completed_at)->not->toBeNull()
        // Partial successful refund marks the payment appropriately.
        ->and($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('rejects requests without or with an invalid API key with 401', function () {
    [$merchant] = refundApiMerchant();
    $payment = Payment::factory()->for($merchant)->create();

    test()->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000])
        ->assertUnauthorized();

    postRefund($payment->reference, 'invalid-key-value', ['amount' => 1000])
        ->assertUnauthorized();
});

it('returns an identical generic 404 for unknown and cross-merchant payments', function () {
    [, $rawKey] = refundApiMerchant();

    $otherMerchant = Merchant::factory()->create();
    $foreignPayment = Payment::factory()->for($otherMerchant)->create();

    $unknown = postRefund('pay_does_not_exist', $rawKey, ['amount' => 1000]);
    $foreign = postRefund($foreignPayment->reference, $rawKey, ['amount' => 1000]);

    expect($unknown->status())->toBe(404)
        ->and($unknown->json())->toBe(['message' => 'Not found.'])
        ->and($foreign->status())->toBe(404)
        // Existence of another merchant's payment is never leaked.
        ->and($foreign->json())->toBe($unknown->json())
        ->and(Refund::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Request validation
// ---------------------------------------------------------------------------

it('enforces strict integer amount validation', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    foreach ([10.50, '1000', 0, -100] as $invalidAmount) {
        postRefund($payment->reference, $rawKey, ['amount' => $invalidAmount])
            ->assertStatus(422);
    }

    expect(Refund::count())->toBe(0);
});

it('normalizes lowercase currency and rejects mismatched or invalid currency', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    postRefund($payment->reference, $rawKey, ['amount' => 1000, 'currency' => 'usd'])
        ->assertCreated()
        ->assertJsonPath('data.currency', 'USD');

    postRefund($payment->reference, $rawKey, ['amount' => 1000, 'currency' => 'EUR'])
        ->assertStatus(422);

    postRefund($payment->reference, $rawKey, ['amount' => 1000, 'currency' => 'US'])
        ->assertStatus(422);
});

it('allows refunds without a reason and accepts one when provided', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    postRefund($payment->reference, $rawKey, ['amount' => 100])
        ->assertCreated()
        ->assertJsonPath('data.reason', null);

    postRefund($payment->reference, $rawKey, ['amount' => 100, 'reason' => 'Damaged goods'])
        ->assertCreated()
        ->assertJsonPath('data.reason', 'Damaged goods');
});

it('never accepts merchant identity or provider through refund input', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);
    $otherMerchant = Merchant::factory()->create();

    postRefund($payment->reference, $rawKey, [
        'amount' => 1000,
        'merchant_id' => $otherMerchant->id,
        'provider' => 'stripe',
    ])->assertCreated()
        // Provider is derived from the original attempt, never from input.
        ->assertJsonPath('data.provider', 'mock');

    $refund = Refund::query()->sole();

    expect($refund->merchant_id)->toBe($payment->merchant_id)
        ->and($refund->merchant_id)->not->toBe($otherMerchant->id);
});

it('rejects foreign and nonexistent payment attempts', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    $otherMerchant = Merchant::factory()->create();
    [$otherPayment, $foreignAttempt] = succeededPaymentWithAttempt($otherMerchant);

    postRefund($payment->reference, $rawKey, ['amount' => 1000, 'payment_attempt_id' => $foreignAttempt->id])
        ->assertStatus(422);

    postRefund($payment->reference, $rawKey, ['amount' => 1000, 'payment_attempt_id' => 999999])
        ->assertStatus(422);

    expect(Refund::count())->toBe(0)
        ->and($otherPayment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

// ---------------------------------------------------------------------------
// Concurrency / over-refund protection (locked creation path)
// ---------------------------------------------------------------------------

it('rejects an over-refund against the reserved balance inside the locked creation flow', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    // Existing reservation committed by another (concurrent) request.
    Refund::factory()->forPayment($payment)->create(['amount' => 5000, 'status' => RefundStatus::Succeeded]);

    expect($payment->remainingRefundableAmount())->toBe(5000);

    postRefund($payment->reference, $rawKey, ['amount' => 6000])
        ->assertStatus(422)
        ->assertJsonPath('message', 'The refund amount exceeds the remaining refundable balance of the payment.');

    // Only the pre-existing reservation exists — nothing was created.
    expect(Refund::count())->toBe(1)
        ->and($payment->remainingRefundableAmount())->toBe(5000);
});

it('allows a refund that exactly consumes the remaining balance', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    Refund::factory()->forPayment($payment)->create(['amount' => 5000, 'status' => RefundStatus::Succeeded]);

    postRefund($payment->reference, $rawKey, ['amount' => 5000])
        ->assertCreated()
        ->assertJsonPath('data.status', RefundStatus::Succeeded->value);

    // Full successful refund marks the payment refunded.
    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded);
});

// ---------------------------------------------------------------------------
// Provider selection
// ---------------------------------------------------------------------------

it('uses the latest successful attempt when no attempt is specified', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    // Older attempt through another provider (never selected for refunds),
    // then a newer successful mock attempt (created after, higher id).
    PaymentAttempt::factory()->forPayment($payment)->failed()->create([
        'provider' => 'stripe',
        'provider_payment_id' => 'pi_failed_123',
    ]);

    postRefund($payment->reference, $rawKey, ['amount' => 1000])
        ->assertCreated()
        ->assertJsonPath('data.provider', 'mock');
});

it('uses an explicitly provided successful attempt as the refund provider', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment, $mockAttempt] = succeededPaymentWithAttempt($merchant);

    // A NEWER successful mock attempt exists, but the merchant explicitly
    // targets the older one — the explicit choice wins.
    $newerAttempt = PaymentAttempt::factory()->forPayment($payment)->succeeded()->create([
        'provider' => 'mock',
        'provider_payment_id' => 'pi_newer_123',
    ]);

    expect($newerAttempt->id)->toBeGreaterThan($mockAttempt->id);

    postRefund($payment->reference, $rawKey, [
        'amount' => 1000,
        'payment_attempt_id' => $mockAttempt->id,
    ])->assertCreated()
        ->assertJsonPath('data.provider', 'mock');

    expect(Refund::query()->sole()->payment_attempt_id)->toBe($mockAttempt->id);
});

it('fails with a controlled error when no successful attempt exists', function () {
    [$merchant, $rawKey] = refundApiMerchant();

    $payment = Payment::factory()->for($merchant)->create([
        'amount' => 10000,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
    ]);

    postRefund($payment->reference, $rawKey, ['amount' => 1000])
        ->assertStatus(409)
        ->assertJsonPath('message', 'No refund-capable provider could be determined for this payment.')
        ->assertJsonPath('data.status', RefundStatus::Pending->value);

    // The refund is created but stays pending — no silent provider choice.
    expect(Refund::query()->sole()->status)->toBe(RefundStatus::Pending);
});

it('fails with a controlled error when the original provider does not support refunds', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant, 10000, 'payu');

    postRefund($payment->reference, $rawKey, ['amount' => 1000])
        ->assertStatus(422)
        ->assertJsonPath('data.status', RefundStatus::Pending->value);

    expect(Refund::query()->sole()->status)->toBe(RefundStatus::Pending)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

// ---------------------------------------------------------------------------
// Execution semantics and payment refund status
// ---------------------------------------------------------------------------

it('marks a failed refund without changing the payment status', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    // Mock-specific deterministic failure via request metadata.
    postRefund($payment->reference, $rawKey, [
        'amount' => 1000,
        'metadata' => ['mock_refund_should_fail' => true],
    ])->assertCreated()
        ->assertJsonPath('data.status', RefundStatus::Failed->value)
        ->assertJsonPath('data.failure_code', 'mock_refund_failed');

    $refund = Refund::query()->sole();

    expect($refund->failure_message)->not->toBeNull()
        ->and($refund->completed_at)->not->toBeNull()
        // Reservation released, payment untouched.
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($payment->remainingRefundableAmount())->toBe(10000);
});

it('accumulates multiple successful partial refunds and marks the payment refunded', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    postRefund($payment->reference, $rawKey, ['amount' => 3000])->assertCreated();
    expect($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);

    postRefund($payment->reference, $rawKey, ['amount' => 2000])->assertCreated();
    expect($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->totalSuccessfulRefundAmount())->toBe(5000);

    postRefund($payment->reference, $rawKey, ['amount' => 5000])->assertCreated();
    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->totalSuccessfulRefundAmount())->toBe(10000);
});

it('never executes a terminal refund twice', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    postRefund($payment->reference, $rawKey, ['amount' => 1000])->assertCreated();

    $refund = Refund::query()->sole();
    expect($refund->status)->toBe(RefundStatus::Succeeded);

    // A second execution attempt (e.g. a racing duplicate) is rejected.
    expect(fn () => app(ProcessRefund::class)->process($refund))
        ->toThrow(RefundNotProcessableException::class)
        // Exactly one execution happened: still one provider refund id.
        ->and($refund->refresh()->provider_refund_id)->toStartWith('mock_refund_');
});

it('exposes only safe fields in the refund resource', function () {
    [$merchant, $rawKey] = refundApiMerchant();
    [$payment] = succeededPaymentWithAttempt($merchant);

    postRefund($payment->reference, $rawKey, ['amount' => 1000, 'metadata' => ['secret_note' => 'internal']])
        ->assertCreated()
        ->assertJsonMissingExact(['merchant_id' => $merchant->id])
        ->assertJsonMissingExact(['payment_id' => $payment->id])
        ->assertJsonStructure(['data' => [
            'reference', 'payment_reference', 'provider', 'provider_refund_id',
            'amount', 'currency', 'status', 'reason', 'failure_code',
            'failure_message', 'requested_at', 'completed_at', 'created_at', 'updated_at',
        ]])
        // Only the whitelisted keys — no internal ids, no metadata.
        ->assertJsonCount(14, 'data')
        // Secret-bearing metadata is never echoed back.
        ->assertJsonMissing(['secret_note']);
});
