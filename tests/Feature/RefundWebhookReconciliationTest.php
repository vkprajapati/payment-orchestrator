<?php

use App\Actions\Payments\ReconcileRefundWebhook;
use App\Data\Payments\PaymentProviderWebhookResult;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Services\Payments\Providers\StripePaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Build a provider-neutral refund webhook result as parsed by a provider.
 */
function refundWebhookResult(
    string $provider,
    string $providerRefundId,
    ?string $status,
    array $metadata = [],
    bool $valid = true,
): PaymentProviderWebhookResult {
    return new PaymentProviderWebhookResult(
        provider: $provider,
        providerPaymentId: null,
        providerRefundId: $providerRefundId,
        event: 'refund.'.($status ?? 'unknown'),
        status: $status,
        valid: $valid,
        metadata: $metadata,
    );
}

/**
 * Create a payment with a refund for webhook reconciliation tests. A
 * seeded succeeded refund implies the parent payment was already
 * reconciled (partially_refunded/refunded), mirroring real state.
 */
function webhookRefund(
    string $provider,
    string $providerRefundId,
    ?string $status = null,
    ?Payment $payment = null,
    int $amount = 3000,
): Refund {
    $status ??= RefundStatus::Pending->value;

    $payment ??= Payment::factory()->succeeded()->create([
        'currency' => 'USD',
        'amount' => 10000,
    ]);

    $refund = Refund::factory()->forPayment($payment, $amount)->create([
        'provider' => $provider,
        'provider_refund_id' => $providerRefundId,
        'status' => $status,
    ]);

    if ($status === RefundStatus::Succeeded->value) {
        $refunded = $payment->refunds()
            ->where('status', RefundStatus::Succeeded->value)
            ->sum('amount');

        $payment->status = $refunded >= $payment->amount
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;
        $payment->save();
    }

    return $refund;
}

function reconcileRefundWebhook(PaymentProviderWebhookResult $webhook)
{
    return app(ReconcileRefundWebhook::class)->reconcile($webhook);
}

// ---------------------------------------------------------------------------
// Verification / security (HTTP level)
// ---------------------------------------------------------------------------

it('a valid mock refund webhook reaches reconciliation and updates the refund', function () {
    $refund = webhookRefund('mock', 'mock_refund_1');

    $this->postJson('/api/v1/webhooks/mock', [
        'provider_refund_id' => 'mock_refund_1',
        'event' => 'refund.succeeded',
        'status' => 'succeeded',
    ])->assertOk()->assertExactJson(['received' => true]);

    expect($refund->refresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->completed_at)->not->toBeNull()
        ->and($refund->payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('an unknown provider refund id is acknowledged without any mutation', function () {
    $this->postJson('/api/v1/webhooks/mock', [
        'provider_refund_id' => 'mock_refund_does_not_exist',
        'event' => 'refund.succeeded',
        'status' => 'succeeded',
    ])->assertOk()->assertExactJson(['received' => true]);

    expect(Refund::count())->toBe(0);
});

it('a malformed refund webhook is rejected without mutation', function () {
    $refund = webhookRefund('mock', 'mock_refund_bad');

    $this->postJson('/api/v1/webhooks/mock', [
        'provider_refund_id' => 'mock_refund_bad',
    ])->assertBadRequest()->assertExactJson(['message' => 'Invalid webhook.']);

    expect($refund->refresh()->status)->toBe(RefundStatus::Pending);
});

it('a payment webhook cannot accidentally reconcile a refund', function () {
    // The same provider refund id is stored, but the webhook identifies a
    // PAYMENT (provider_payment_id), not a refund. It must be ignored.
    $refund = webhookRefund('mock', 'mock_same', RefundStatus::Pending->value);

    $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'mock_same',
        'event' => 'payment.succeeded',
        'status' => 'succeeded',
    ])->assertOk();

    expect($refund->refresh()->status)->toBe(RefundStatus::Pending);
});

it('an unknown provider returns a generic 404 for refund webhooks', function () {
    $this->postJson('/api/v1/webhooks/paypal', [
        'provider_refund_id' => 'x',
        'event' => 'refund.succeeded',
    ])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
});
// ---------------------------------------------------------------------------
// Refund identification
// ---------------------------------------------------------------------------

it('locates a refund by provider and provider refund id', function () {
    $refund = webhookRefund('mock', 'mock_refund_match');

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_refund_match', 'succeeded'));

    expect($result->found)->toBeTrue()
        ->and($result->transitioned)->toBeTrue()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Succeeded);
});

it('an unknown provider refund id causes no mutation', function () {
    $before = Refund::count();

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_refund_missing', 'succeeded'));

    expect($result->found)->toBeFalse()
        ->and($result->transitioned)->toBeFalse()
        ->and(Refund::count())->toBe($before);
});

it('does not locate a refund under another provider with the same id', function () {
    $refund = webhookRefund('stripe', 're_same');

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 're_same', 'succeeded'));

    expect($result->found)->toBeFalse()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Pending);
});

// ---------------------------------------------------------------------------
// Refund status transitions
// ---------------------------------------------------------------------------

it('transitions a pending refund to processing', function () {
    $refund = webhookRefund('mock', 'mock_proc');

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_proc', 'processing'));

    expect($result->found)->toBeTrue()
        ->and($result->transitioned)->toBeTrue()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Processing);
});

it('transitions a pending refund to succeeded', function () {
    $refund = webhookRefund('mock', 'mock_succ');

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_succ', 'succeeded'));

    expect($result->found)->toBeTrue()
        ->and($result->transitioned)->toBeTrue()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->completed_at)->not->toBeNull();
});

it('transitions a pending refund to failed', function () {
    $refund = webhookRefund('mock', 'mock_fail');

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_fail', 'failed'));

    expect($result->transitioned)->toBeTrue()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Failed)
        ->and($refund->failure_code)->not->toBeNull()
        ->and($refund->failure_message)->not->toBeNull();
});

it('transitions a pending refund to cancelled', function () {
    $refund = webhookRefund('mock', 'mock_cx_pending');

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_cx_pending', 'cancelled'));

    expect($result->transitioned)->toBeTrue()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Cancelled);
});

it('transitions a processing refund to succeeded', function () {
    $refund = webhookRefund('mock', 'mock_ps', RefundStatus::Processing->value);

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_ps', 'succeeded'));

    expect($result->transitioned)->toBeTrue()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Succeeded);
});

it('transitions a processing refund to failed', function () {
    $refund = webhookRefund('mock', 'mock_pf', RefundStatus::Processing->value);

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_pf', 'failed'));

    expect($result->transitioned)->toBeTrue()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Failed);
});

it('allows a provider-authoritative failed to succeeded correction', function () {
    $refund = webhookRefund('mock', 'mock_rec', RefundStatus::Failed->value);

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_rec', 'succeeded'));

    expect($result->transitioned)->toBeTrue()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->failure_code)->toBeNull()
        ->and($refund->failure_message)->toBeNull()
        ->and($refund->payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('does not resurrect a failed refund to processing', function () {
    $refund = webhookRefund('mock', 'mock_noproc', RefundStatus::Failed->value);

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_noproc', 'processing'));

    expect($result->transitioned)->toBeFalse()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Failed);
});
it('a succeeded refund is never downgraded', function () {
    $refund = webhookRefund('mock', 'mock_keep', RefundStatus::Succeeded->value);

    foreach (['failed', 'cancelled', 'processing', 'pending'] as $staleStatus) {
        $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_keep', $staleStatus));

        expect($result->transitioned)->toBeFalse();
    }

    expect($refund->refresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($refund->payment->fresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('a cancelled refund is never resurrected', function () {
    $refund = webhookRefund('mock', 'mock_cx', RefundStatus::Cancelled->value);

    foreach (['succeeded', 'failed', 'processing', 'pending'] as $staleStatus) {
        $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_cx', $staleStatus));

        expect($result->transitioned)->toBeFalse();
    }

    expect($refund->refresh()->status)->toBe(RefundStatus::Cancelled);
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('a duplicate succeeded webhook is an idempotent no-op', function () {
    $refund = webhookRefund('mock', 'mock_dup', RefundStatus::Succeeded->value);
    $payment = $refund->payment;

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_dup', 'succeeded'));

    expect($result->found)->toBeTrue()
        ->and($result->transitioned)->toBeFalse()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($payment->totalSuccessfulRefundAmount())->toBe(3000)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('a duplicate failed webhook is an idempotent no-op', function () {
    $refund = webhookRefund('mock', 'mock_ff', RefundStatus::Failed->value);

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_ff', 'failed'));

    expect($result->transitioned)->toBeFalse()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Failed);
});

it('a repeated processing webhook is an idempotent no-op', function () {
    $refund = webhookRefund('mock', 'mock_pp', RefundStatus::Processing->value);

    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_pp', 'processing'));

    expect($result->transitioned)->toBeFalse()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Processing);
});

it('a duplicate succeeded webhook does not alter payment totals twice', function () {
    $payment = Payment::factory()->succeeded()->create(['amount' => 10000, 'currency' => 'USD']);
    $refund = webhookRefund('mock', 'mock_double', RefundStatus::Pending->value, $payment, 3000);

    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_double', 'succeeded'));
    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_double', 'succeeded'));
    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_double', 'succeeded'));

    expect($refund->refresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($payment->refresh()->totalSuccessfulRefundAmount())->toBe(3000)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});
// ---------------------------------------------------------------------------
// Parent payment reconciliation
// ---------------------------------------------------------------------------

it('a successful partial refund updates the payment to partially_refunded', function () {
    $payment = Payment::factory()->succeeded()->create(['amount' => 10000, 'currency' => 'USD']);
    $refund = webhookRefund('mock', 'mock_partial', RefundStatus::Pending->value, $payment, 3000);

    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_partial', 'succeeded'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);
});

it('cumulative successful refunds update the payment to refunded', function () {
    $payment = Payment::factory()->succeeded()->create(['amount' => 10000, 'currency' => 'USD']);
    webhookRefund('mock', 'mock_a', RefundStatus::Pending->value, $payment, 3000);
    webhookRefund('mock', 'mock_b', RefundStatus::Pending->value, $payment, 7000);

    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_a', 'succeeded'));
    expect($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded);

    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_b', 'succeeded'));
    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded);

    // Refund B late duplicate: payment remains refunded.
    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_b', 'succeeded'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($payment->totalSuccessfulRefundAmount())->toBe(10000);
});

it('a failed refund does not alter the payment incorrectly', function () {
    $payment = Payment::factory()->succeeded()->create(['amount' => 10000, 'currency' => 'USD']);
    $refund = webhookRefund('mock', 'mock_failpay', RefundStatus::Pending->value, $payment, 5000);

    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_failpay', 'failed'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($refund->refresh()->status)->toBe(RefundStatus::Failed);
});

it('a cancelled refund does not alter the payment incorrectly', function () {
    $payment = Payment::factory()->succeeded()->create(['amount' => 10000, 'currency' => 'USD']);
    $refund = webhookRefund('mock', 'mock_cxpay', RefundStatus::Pending->value, $payment, 5000);

    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_cxpay', 'cancelled'));

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded)
        ->and($refund->refresh()->status)->toBe(RefundStatus::Cancelled);
});

// ---------------------------------------------------------------------------
// Concurrency / ordering safety
// ---------------------------------------------------------------------------

it('out-of-order delivery (succeeded then stale failed) keeps the success', function () {
    $refund = webhookRefund('mock', 'mock_ooo', RefundStatus::Pending->value);

    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_ooo', 'succeeded'));
    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_ooo', 'failed'));

    expect($result->transitioned)->toBeFalse()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Succeeded);
});

it('a failed then corrected success updates the payment exactly once', function () {
    $payment = Payment::factory()->succeeded()->create(['amount' => 10000, 'currency' => 'USD']);
    $refund = webhookRefund('mock', 'mock_correct', RefundStatus::Pending->value, $payment, 3000);

    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_correct', 'failed'));
    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_correct', 'succeeded'));
    reconcileRefundWebhook(refundWebhookResult('mock', 'mock_correct', 'succeeded'));

    expect($refund->refresh()->status)->toBe(RefundStatus::Succeeded)
        ->and($payment->refresh()->status)->toBe(PaymentStatus::PartiallyRefunded)
        ->and($payment->totalSuccessfulRefundAmount())->toBe(3000);
});
it('a payment webhook cannot accidentally reconcile a refund at the action level', function () {
    $refund = webhookRefund('mock', 'mock_shared', RefundStatus::Pending->value);

    // A payment event carries ONLY a provider payment id.
    $result = reconcileRefundWebhook(new PaymentProviderWebhookResult(
        provider: 'mock',
        providerPaymentId: 'mock_shared',
        event: 'payment.succeeded',
        status: 'succeeded',
        valid: true,
    ));

    expect($result->found)->toBeFalse()
        ->and($refund->refresh()->status)->toBe(RefundStatus::Pending);
});

it('a refund webhook cannot accidentally reconcile a payment attempt', function () {
    $payment = Payment::factory()->succeeded()->create(['amount' => 10000, 'currency' => 'USD']);
    $attempt = PaymentAttempt::factory()->forPayment($payment)->create([
        'provider' => 'mock',
        'provider_payment_id' => 'pi_same',
        'status' => 'pending',
    ]);

    webhookRefund('mock', 'mock_shared2', RefundStatus::Pending->value, $payment);

    // A refund event carries ONLY a provider refund id.
    $result = reconcileRefundWebhook(refundWebhookResult('mock', 'mock_shared2', 'succeeded'));

    expect($result->found)->toBeTrue()
        ->and($attempt->refresh()->status->value)->toBe('pending');
});

// ---------------------------------------------------------------------------
// Stripe provider parsing
// ---------------------------------------------------------------------------

it('parses a Stripe refund.updated succeeded event into a refund result', function () {
    config()->set('payments.providers.stripe.webhook_secret', 'whsec_test');

    $provider = app(StripePaymentProvider::class);

    $result = $provider->parseWebhook([
        'type' => 'refund.updated',
        'data' => ['object' => [
            'id' => 're_123',
            'status' => 'succeeded',
            'amount' => 3000,
        ]],
    ]);

    expect($result->valid)->toBeTrue()
        ->and($result->providerRefundId)->toBe('re_123')
        ->and($result->providerPaymentId)->toBeNull()
        ->and($result->status)->toBe(RefundStatus::Succeeded->value);
});

it('parses a Stripe refund.updated failed event into a refund result', function () {
    config()->set('payments.providers.stripe.webhook_secret', 'whsec_test');

    $provider = app(StripePaymentProvider::class);

    $result = $provider->parseWebhook([
        'type' => 'refund.updated',
        'data' => ['object' => [
            'id' => 're_fail',
            'status' => 'failed',
            'amount' => 3000,
        ]],
    ]);

    expect($result->valid)->toBeTrue()
        ->and($result->providerRefundId)->toBe('re_fail')
        ->and($result->status)->toBe(RefundStatus::Failed->value);
});

it('does not treat a charge.refunded event as a refund webhook', function () {
    config()->set('payments.providers.stripe.webhook_secret', 'whsec_test');

    $provider = app(StripePaymentProvider::class);

    // charge.refunded carries the charge object — not a refund identifier.
    $result = $provider->parseWebhook([
        'type' => 'charge.refunded',
        'data' => ['object' => [
            'id' => 'ch_charge',
            'refunded' => true,
        ]],
    ]);

    expect($result->valid)->toBeTrue()
        ->and($result->providerRefundId)->toBeNull()
        ->and($result->providerPaymentId)->toBe('ch_charge');
});
