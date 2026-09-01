<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Enums\PaymentStatus;
use App\Models\AuditEvent;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant with a real API key, returning the raw key.
 *
 * (audit-prefixed helpers avoid clashing with sibling test files under the
 * same Pest process.)
 *
 * @return array{0: Merchant, 1: string}
 */
function auditMerchant(string $name = 'Audit Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

function auditAuth(string $rawKey): array
{
    return ['Authorization' => "Bearer {$rawKey}"];
}

/**
 * A succeeded payment (with a successful mock attempt) owned by the
 * merchant — the precondition for refunds.
 */
function auditSucceededPayment(Merchant $merchant, int $amount = 10000): Payment
{
    $payment = Payment::factory()->for($merchant)->create([
        'amount' => $amount,
        'currency' => 'USD',
        'status' => PaymentStatus::Succeeded,
    ]);

    PaymentAttempt::factory()->forPayment($payment)->succeeded()->create([
        'provider' => 'mock',
        'provider_payment_id' => 'pi_audit_1',
    ]);

    return $payment;
}

// ---------------------------------------------------------------------------
// Payment creation: exactly once per execution, never on replay
// ---------------------------------------------------------------------------

it('records payment.created exactly once across an idempotent replay', function () {
    [$merchant, $rawKey] = auditMerchant();
    $headers = auditAuth($rawKey) + ['Idempotency-Key' => 'audit-create-1'];
    $payload = ['amount' => 1000, 'currency' => 'USD'];

    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();
    $this->postJson('/api/v1/payments', $payload, $headers)->assertCreated();

    expect(AuditEvent::count())->toBe(1);

    $event = AuditEvent::query()->sole();

    expect($event->merchant_id)->toBe($merchant->id)
        ->and($event->event)->toBe(AuditEventName::PaymentCreated)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($event->http_method)->toBe('POST')
        ->and($event->path)->toBe('api/v1/payments')
        ->and($event->response_status)->toBe(201)
        ->and($event->payment_reference)->toStartWith('pay_')
        ->and($event->refund_reference)->toBeNull()
        ->and($event->idempotency_replayed)->toBeFalse()
        ->and($event->metadata)->toBe(['amount' => 1000, 'currency' => 'USD'])
        ->and($event->performed_at)->not->toBeNull();
});

it('records payment.created once per request when no idempotency key is sent', function () {
    [$merchant, $rawKey] = auditMerchant();

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], auditAuth($rawKey))->assertCreated();
    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], auditAuth($rawKey))->assertCreated();

    expect(AuditEvent::count())->toBe(2)
        ->and(AuditEvent::query()->where('merchant_id', $merchant->id)->count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Payment processing: exactly once per execution, never on replay
// ---------------------------------------------------------------------------

it('records payment.processing_requested exactly once across a replay', function () {
    [$merchant, $rawKey] = auditMerchant();
    $payment = Payment::factory()->for($merchant)->create(['amount' => 1000, 'currency' => 'USD']);
    $headers = auditAuth($rawKey) + ['Idempotency-Key' => 'audit-process-1'];

    $this->postJson("/api/v1/payments/{$payment->reference}/process", [], $headers)->assertOk();
    $this->postJson("/api/v1/payments/{$payment->reference}/process", [], $headers)->assertOk();

    expect(AuditEvent::count())->toBe(1);

    $event = AuditEvent::query()->sole();

    expect($event->event)->toBe(AuditEventName::PaymentProcessingRequested)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($event->response_status)->toBe(200)
        ->and($event->payment_reference)->toBe($payment->reference)
        ->and($event->metadata)->toBe(['provider' => 'mock', 'status' => 'succeeded']);
});

it('records a failure outcome when reprocessing an already-succeeded payment', function () {
    [$merchant, $rawKey] = auditMerchant();
    $payment = Payment::factory()->for($merchant)->create(['amount' => 1000, 'currency' => 'USD']);

    $this->postJson("/api/v1/payments/{$payment->reference}/process", [], auditAuth($rawKey))->assertOk();
    $this->postJson("/api/v1/payments/{$payment->reference}/process", [], auditAuth($rawKey))->assertStatus(409);

    expect(AuditEvent::count())->toBe(2);

    $events = AuditEvent::query()->orderBy('id')->get();

    expect($events[0]->outcome)->toBe(AuditOutcome::Success)
        ->and($events[0]->response_status)->toBe(200)
        ->and($events[1]->outcome)->toBe(AuditOutcome::Failure)
        ->and($events[1]->response_status)->toBe(409)
        ->and($events[1]->metadata)->toBe(['status' => 'succeeded'])
        // The provider was still contacted exactly once — no double charge.
        ->and($payment->attempts()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Refund creation: exactly once per execution, never on replay
// ---------------------------------------------------------------------------

it('records refund.created exactly once across a replay', function () {
    [$merchant, $rawKey] = auditMerchant();
    $payment = auditSucceededPayment($merchant);
    $headers = auditAuth($rawKey) + ['Idempotency-Key' => 'audit-refund-1'];

    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000], $headers)->assertCreated();
    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 1000], $headers)->assertCreated();

    expect(AuditEvent::count())->toBe(1);

    $event = AuditEvent::query()->sole();

    expect($event->event)->toBe(AuditEventName::RefundCreated)
        ->and($event->outcome)->toBe(AuditOutcome::Success)
        ->and($event->response_status)->toBe(201)
        ->and($event->payment_reference)->toBe($payment->reference)
        ->and($event->refund_reference)->toStartWith('ref_')
        ->and($event->metadata)->toBe(['amount' => 1000, 'currency' => 'USD']);
});

it('records a controlled over-refund failure exactly once across a replay', function () {
    [$merchant, $rawKey] = auditMerchant();
    $payment = auditSucceededPayment($merchant, amount: 5000);
    $headers = auditAuth($rawKey) + ['Idempotency-Key' => 'audit-overrefund-1'];

    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 9999], $headers)->assertStatus(422);
    $this->postJson("/api/v1/payments/{$payment->reference}/refunds", ['amount' => 9999], $headers)->assertStatus(422);

    expect(AuditEvent::count())->toBe(1);

    $event = AuditEvent::query()->sole();

    expect($event->event)->toBe(AuditEventName::RefundCreated)
        ->and($event->outcome)->toBe(AuditOutcome::Failure)
        ->and($event->response_status)->toBe(422)
        ->and($event->payment_reference)->toBe($payment->reference)
        ->and($event->refund_reference)->toBeNull()
        // The failure path persists only whitelisted metadata.
        ->and($event->metadata)->toBe(['amount' => 9999, 'currency' => 'USD']);
});

// ---------------------------------------------------------------------------
// Unknown / cross-merchant references never pollute the audit trail
// ---------------------------------------------------------------------------

it('does not write audit records for unknown processing references', function () {
    [, $rawKey] = auditMerchant();

    $this->postJson('/api/v1/payments/pay_does_not_exist/process', [], auditAuth($rawKey))
        ->assertNotFound();

    expect(AuditEvent::count())->toBe(0);
});

it('does not write audit records for unknown refund references', function () {
    [, $rawKey] = auditMerchant();

    $this->postJson('/api/v1/payments/pay_does_not_exist/refunds', ['amount' => 1000], auditAuth($rawKey))
        ->assertNotFound();

    expect(AuditEvent::count())->toBe(0);
});

it('scopes audit records to the paying merchant only', function () {
    [$merchantA, $keyA] = auditMerchant('Merchant A');
    [$merchantB, $keyB] = auditMerchant('Merchant B');

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], auditAuth($keyA))->assertCreated();
    $this->postJson('/api/v1/payments', ['amount' => 2000, 'currency' => 'USD'], auditAuth($keyB))->assertCreated();

    expect(AuditEvent::count())->toBe(2)
        ->and(AuditEvent::query()->where('merchant_id', $merchantA->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('merchant_id', $merchantB->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('merchant_id', $merchantA->id)->sole()->payment_reference)
        ->toStartWith('pay_');
});

// ---------------------------------------------------------------------------
// Append-only nature and controlled contents
// ---------------------------------------------------------------------------

it('records event names consistently', function () {
    [$merchant, $rawKey] = auditMerchant();
    $payment = Payment::factory()->for($merchant)->create(['amount' => 1000, 'currency' => 'USD']);

    $this->postJson('/api/v1/payments', ['amount' => 1000, 'currency' => 'USD'], auditAuth($rawKey))->assertCreated();
    $this->postJson("/api/v1/payments/{$payment->reference}/process", [], auditAuth($rawKey))->assertOk();

    $events = AuditEvent::query()->orderBy('id')->get()->map->event->all();

    expect($events)->toBe([
        AuditEventName::PaymentCreated,
        AuditEventName::PaymentProcessingRequested,
    ]);
});
