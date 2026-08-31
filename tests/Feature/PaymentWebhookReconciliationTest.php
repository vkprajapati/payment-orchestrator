<?php

use App\Actions\Payments\ReconcilePaymentWebhook;
use App\Data\Payments\PaymentProviderWebhookResult;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Build a provider-neutral webhook result as parsed by a provider.
 */
function webhookResult(
    string $provider,
    string $providerPaymentId,
    ?string $status,
    array $metadata = [],
    bool $valid = true,
): PaymentProviderWebhookResult {
    return new PaymentProviderWebhookResult(
        provider: $provider,
        providerPaymentId: $providerPaymentId,
        event: 'payment.'.($status ?? 'unknown'),
        status: $status,
        valid: $valid,
        metadata: $metadata,
    );
}

/**
 * Create a payment with a single attempt for webhook reconciliation tests.
 */
function webhookAttempt(
    string $provider,
    string $providerPaymentId,
    ?string $status = null,
    ?Payment $payment = null,
): PaymentAttempt {
    $status ??= PaymentAttemptStatus::Pending->value;

    $payment ??= Payment::factory()->create([
        'currency' => 'USD',
        'amount' => 1050,
    ]);

    // Mirror real state: a seeded succeeded attempt implies its payment was
    // already marked succeeded by an earlier webhook reconciliation.
    if ($status === PaymentAttemptStatus::Succeeded->value && ! $payment->isSucceeded()) {
        $payment->status = PaymentStatus::Succeeded;
        $payment->save();
    }

    return PaymentAttempt::factory()->forPayment($payment)->create([
        'provider' => $provider,
        'provider_payment_id' => $providerPaymentId,
        'status' => $status,
        'response_metadata' => ['prior' => 'kept'],
    ]);
}

function reconcileWebhook(PaymentProviderWebhookResult $webhook)
{
    return app(ReconcilePaymentWebhook::class)->reconcile($webhook);
}

// ---------------------------------------------------------------------------
// Verification
// ---------------------------------------------------------------------------

it('a valid mock webhook reaches reconciliation and updates attempt and payment', function () {
    $attempt = webhookAttempt('mock', 'mock_pay_1');

    $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'mock_pay_1',
        'event' => 'payment.succeeded',
        'status' => 'succeeded',
    ])->assertOk()->assertExactJson(['received' => true]);

    expect($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attempt->payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('an invalid signature does not mutate the database', function () {
    config()->set('payments.providers.p24', [
        'enabled' => true,
        'environment' => 'sandbox',
        'merchant_id' => '12345',
        'pos_id' => '12345',
        'crc_key' => 'abc123',
        'api_key' => 'secret_key',
    ]);

    $attempt = webhookAttempt('p24', 'P24-ORDER-9');

    $this->postJson('/api/v1/webhooks/p24', [
        'merchantId' => '12345',
        'posId' => '12345',
        'sessionId' => 'pay_1',
        'amount' => '1050',
        'currency' => 'PLN',
        'orderId' => 'P24-ORDER-9',
        'sign' => 'not-a-real-signature',
    ])->assertBadRequest()->assertExactJson(['message' => 'Invalid webhook.']);

    expect($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Pending)
        ->and($attempt->payment->refresh()->status)->toBe(PaymentStatus::Pending);
});

it('a malformed webhook does not mutate the database', function () {
    $attempt = webhookAttempt('mock', 'mock_bad');

    $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'mock_bad',
    ])->assertBadRequest();

    expect($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Pending);
});

it('an unknown provider returns a generic 404', function () {
    $this->postJson('/api/v1/webhooks/paypal', [
        'provider_payment_id' => 'x',
        'event' => 'payment.succeeded',
    ])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});

// ---------------------------------------------------------------------------
// Attempt lookup
// ---------------------------------------------------------------------------

it('finds a matching attempt by provider and provider payment id', function () {
    $attempt = webhookAttempt('stripe', 'pi_matching');

    $result = reconcileWebhook(webhookResult('stripe', 'pi_matching', 'succeeded'));

    expect($result->found)->toBeTrue()
        ->and($result->transitioned)->toBeTrue()
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Succeeded);
});

it('an unknown provider payment id is safe and never creates records', function () {
    $beforeAttempts = PaymentAttempt::count();
    $beforePayments = Payment::count();

    $result = reconcileWebhook(webhookResult('mock', 'mock_does_not_exist', 'succeeded'));

    expect($result->found)->toBeFalse()
        ->and($result->transitioned)->toBeFalse()
        ->and(PaymentAttempt::count())->toBe($beforeAttempts)
        ->and(Payment::count())->toBe($beforePayments);
});

it('unknown attempts are still acknowledged by the endpoint without leakage', function () {
    $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'mock_missing',
        'event' => 'payment.succeeded',
        'status' => 'succeeded',
    ])->assertOk();

    expect(PaymentAttempt::count())->toBe(0)
        ->and(Payment::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Attempt status transitions
// ---------------------------------------------------------------------------

it('transitions a pending attempt to processing', function () {
    $attempt = webhookAttempt('mock', 'mock_proc');

    $result = reconcileWebhook(webhookResult('mock', 'mock_proc', 'processing'));

    expect($result->transitioned)->toBeTrue()
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Processing)
        ->and($attempt->started_at)->not->toBeNull();
});

it('transitions a pending attempt to succeeded', function () {
    $attempt = webhookAttempt('mock', 'mock_succ');

    $result = reconcileWebhook(webhookResult('mock', 'mock_succ', 'succeeded'));

    expect($result->found)->toBeTrue()
        ->and($result->transitioned)->toBeTrue()
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attempt->completed_at)->not->toBeNull();
});

it('transitions a pending attempt to failed', function () {
    $attempt = webhookAttempt('mock', 'mock_fail');

    $result = reconcileWebhook(webhookResult('mock', 'mock_fail', 'failed'));

    expect($result->transitioned)->toBeTrue()
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Failed)
        ->and($attempt->failure_code)->not->toBeNull()
        ->and($attempt->failure_message)->not->toBeNull();
});

it('a duplicate succeeded event is an idempotent no-op', function () {
    $attempt = webhookAttempt('mock', 'mock_dup', PaymentAttemptStatus::Succeeded->value);

    $result = reconcileWebhook(webhookResult('mock', 'mock_dup', 'succeeded'));

    expect($result->transitioned)->toBeFalse()
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Succeeded);
});

it('a succeeded attempt is never downgraded by a failed event', function () {
    $attempt = webhookAttempt('mock', 'mock_keep', PaymentAttemptStatus::Succeeded->value);

    $result = reconcileWebhook(webhookResult('mock', 'mock_keep', 'failed'));

    expect($result->transitioned)->toBeFalse()
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attempt->payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('allows a provider-authoritative failed to succeeded correction', function () {
    $attempt = webhookAttempt('mock', 'mock_rec', PaymentAttemptStatus::Failed->value);

    $result = reconcileWebhook(webhookResult('mock', 'mock_rec', 'succeeded'));

    expect($result->transitioned)->toBeTrue()
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Succeeded)
        ->and($attempt->failure_code)->toBeNull()
        ->and($attempt->payment->fresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('a failed attempt is not changed by another failed event', function () {
    $attempt = webhookAttempt('mock', 'mock_ff', PaymentAttemptStatus::Failed->value);

    $result = reconcileWebhook(webhookResult('mock', 'mock_ff', 'failed'));

    expect($result->transitioned)->toBeFalse()
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Failed);
});

it('a cancelled attempt is never resurrected', function () {
    $attempt = webhookAttempt('mock', 'mock_cx', PaymentAttemptStatus::Cancelled->value);

    $result = reconcileWebhook(webhookResult('mock', 'mock_cx', 'succeeded'));

    expect($result->transitioned)->toBeFalse()
        ->and($attempt->refresh()->status)->toBe(PaymentAttemptStatus::Cancelled);
});
