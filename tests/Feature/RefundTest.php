<?php

use App\Actions\Payments\CreateRefund;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * A succeeded payment of a fixed size, ready to be refunded.
 */
function refundablePayment(int $amount = 10000, string $currency = 'USD'): Payment
{
    return Payment::factory()->create([
        'amount' => $amount,
        'currency' => $currency,
        'status' => PaymentStatus::Succeeded,
    ]);
}

/**
 * Create a refund through the domain action under test.
 */
function createRefund(Payment $payment, array $data = []): Refund
{
    return (new CreateRefund)->create($payment, $data);
}

// ---------------------------------------------------------------------------
// Schema
// ---------------------------------------------------------------------------

it('creates the refunds table with all expected columns', function () {
    expect(Schema::hasTable('refunds'))->toBeTrue()
        ->and(Schema::hasColumns('refunds', [
            'id', 'payment_id', 'payment_attempt_id', 'merchant_id', 'reference',
            'provider', 'provider_refund_id', 'amount', 'currency', 'status',
            'reason', 'failure_code', 'failure_message',
            'request_metadata', 'response_metadata',
            'requested_at', 'completed_at', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('creates the documented indexes for refunds', function () {
    $indexes = Schema::getIndexListing('refunds');

    // Refund listing per payment / per merchant, newest first.
    expect($indexes)->toContain('refunds_payment_id_created_at_index')
        ->and($indexes)->toContain('refunds_merchant_id_created_at_index')
        // Operational filters.
        ->and($indexes)->toContain('refunds_provider_index')
        ->and($indexes)->toContain('refunds_status_index')
        // Future refund webhook lookups (index, never globally unique).
        ->and($indexes)->toContain('refunds_provider_provider_refund_id_index')
        // Public reference uniqueness.
        ->and($indexes)->toContain('refunds_reference_unique');
});

it('defaults the refund status to pending in the database', function () {
    $payment = refundablePayment();

    // Raw insert without a status exercises the column default.
    DB::table('refunds')->insert([
        'payment_id' => $payment->id,
        'merchant_id' => $payment->merchant_id,
        'reference' => 'ref_'.Str::ulid(),
        'amount' => 500,
        'currency' => $payment->currency,
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('refunds')->where('payment_id', $payment->id)->value('status'))->toBe('pending');
});

it('enforces the positive amount CHECK constraint at the database level', function () {
    $payment = refundablePayment();

    expect(fn () => Refund::factory()->forPayment($payment)->create(['amount' => 0]))
        ->toThrow(QueryException::class)
        ->and(fn () => Refund::factory()->forPayment($payment)->create(['amount' => -100]))
        ->toThrow(QueryException::class)
        ->and(fn () => Refund::factory()->forPayment($payment)->create(['amount' => 2000]))
        ->not->toThrow(QueryException::class)
        ->and(Refund::count())->toBe(1);
});

it('enforces unique refund references at the database level', function () {
    $payment = refundablePayment();
    $refund = Refund::factory()->forPayment($payment)->create();

    expect(fn () => Refund::factory()->forPayment($payment)->create(['reference' => $refund->reference]))
        ->toThrow(QueryException::class);
});

it('deletes refunds when their payment is deleted', function () {
    $payment = refundablePayment();
    $refund = Refund::factory()->forPayment($payment)->create();

    $payment->delete();

    expect(Refund::query()->whereKey($refund->id)->exists())->toBeFalse()
        ->and(Refund::count())->toBe(0);
});

it('deletes refunds when their merchant is deleted', function () {
    $merchant = Merchant::factory()->create();
    $payment = Payment::factory()->for($merchant)->succeeded()->create();
    $refund = Refund::factory()->forPayment($payment)->create();

    $merchant->delete();

    expect(Refund::query()->whereKey($refund->id)->exists())->toBeFalse()
        ->and(Refund::count())->toBe(0);
});

it('detaches refunds when their payment attempt is deleted', function () {
    $payment = refundablePayment();
    $attempt = PaymentAttempt::factory()->forPayment($payment)->succeeded()->create();
    $refund = Refund::factory()->forPayment($payment)->create(['payment_attempt_id' => $attempt->id]);

    $attempt->delete();

    expect($refund->refresh()->payment_attempt_id)->toBeNull()
        ->and($refund->payment()->count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Model
// ---------------------------------------------------------------------------

it('belongs to a payment, an optional attempt and the merchant', function () {
    $merchant = Merchant::factory()->create();
    $payment = Payment::factory()->for($merchant)->succeeded()->create();
    $attempt = PaymentAttempt::factory()->forPayment($payment)->succeeded()->create();
    $refund = Refund::factory()->forPayment($payment)->create(['payment_attempt_id' => $attempt->id]);

    expect($refund->payment->is($payment))->toBeTrue()
        ->and($refund->paymentAttempt->is($attempt))->toBeTrue()
        ->and($refund->merchant->is($merchant))->toBeTrue()
        ->and($payment->refunds()->count())->toBe(1)
        ->and($attempt->refunds()->count())->toBe(1);
});

it('allows refunds without an attempt association', function () {
    $refund = Refund::factory()->forPayment(refundablePayment())->create();

    expect($refund->payment_attempt_id)->toBeNull()
        ->and($refund->paymentAttempt)->toBeNull();
});

it('casts status to the RefundStatus enum', function () {
    $pending = Refund::factory()->forPayment(refundablePayment())->create();

    expect($pending->status)->toBeInstanceOf(RefundStatus::class)
        ->and($pending->status->value)->toBe('pending')
        ->and(Refund::factory()->forPayment(refundablePayment())->succeeded()->create()->status)
        ->toBe(RefundStatus::Succeeded)
        ->and(Refund::factory()->forPayment(refundablePayment())->processing()->create()->status)
        ->toBe(RefundStatus::Processing);
});

it('round-trips request and response metadata through JSONB', function () {
    $refund = Refund::factory()->forPayment(refundablePayment())->create([
        'request_metadata' => ['requested_by' => 'support', 'ticket' => 'T-42'],
        'response_metadata' => ['provider_ref' => 're_123'],
    ]);

    $refund->refresh();

    expect($refund->request_metadata)->toBe(['requested_by' => 'support', 'ticket' => 'T-42'])
        ->and($refund->response_metadata)->toBe(['provider_ref' => 're_123']);
});

it('exposes terminal and successful helper methods on the model', function () {
    $payment = refundablePayment();

    $pending = Refund::factory()->forPayment($payment)->pending()->create();
    $processing = Refund::factory()->forPayment($payment)->processing()->create();
    $succeeded = Refund::factory()->forPayment($payment)->succeeded()->create();
    $failed = Refund::factory()->forPayment($payment)->failed()->create();
    $cancelled = Refund::factory()->forPayment($payment)->cancelled()->create();

    expect($pending->isTerminal())->toBeFalse()->and($pending->isSuccessful())->toBeFalse()
        ->and($processing->isTerminal())->toBeFalse()->and($processing->isSuccessful())->toBeFalse()
        ->and($succeeded->isTerminal())->toBeTrue()->and($succeeded->isSuccessful())->toBeTrue()
        ->and($failed->isTerminal())->toBeTrue()->and($failed->isSuccessful())->toBeFalse()
        ->and($cancelled->isTerminal())->toBeTrue()->and($cancelled->isSuccessful())->toBeFalse();
});

// ---------------------------------------------------------------------------
// References (action-generated)
// ---------------------------------------------------------------------------

it('generates distinct persisted references that are not derived from database ids', function () {
    $payment = refundablePayment();

    $first = createRefund($payment, ['amount' => 100]);
    $second = createRefund($payment, ['amount' => 100]);

    // Crockford base32 ULID: exactly 26 characters after the ref_ prefix —
    // a numeric database id could never look like this.
    expect($first->reference)->toStartWith('ref_')
        ->and(Str::after($first->reference, 'ref_'))->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($first->reference)->not->toBe($second->reference);
});

// ---------------------------------------------------------------------------
// Amounts
// ---------------------------------------------------------------------------

it('accepts a real integer amount', function () {
    $refund = createRefund(refundablePayment(), ['amount' => 1000]);

    expect($refund->exists)->toBeTrue()
        ->and($refund->amount)->toBe(1000)
        ->and($refund->refresh()->amount)->toBe(1000);
});

it('rejects zero, negative, float and numeric string amounts', function () {
    $payment = refundablePayment();

    expect(fn () => createRefund($payment, ['amount' => 0]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createRefund($payment, ['amount' => -100]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createRefund($payment, ['amount' => 10.50]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createRefund($payment, ['amount' => '1000']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createRefund($payment, []))->toThrow(InvalidArgumentException::class)
        ->and(Refund::count())->toBe(0);
});

it('rejects amounts exceeding the remaining refundable balance', function () {
    $payment = refundablePayment(10000);

    expect(fn () => createRefund($payment, ['amount' => 999999]))->toThrow(InvalidArgumentException::class)
        ->and(Refund::count())->toBe(0)
        // Exactly the full amount is still valid.
        ->and(createRefund($payment, ['amount' => 10000])->exists)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Refund calculation / reservation
// ---------------------------------------------------------------------------

it('reserves pending, processing and succeeded refunds but not failed or cancelled', function () {
    $payment = refundablePayment(10000);

    Refund::factory()->forPayment($payment)->create(['amount' => 2000, 'status' => RefundStatus::Pending]);
    Refund::factory()->forPayment($payment)->create(['amount' => 1000, 'status' => RefundStatus::Processing]);
    Refund::factory()->forPayment($payment)->create(['amount' => 3000, 'status' => RefundStatus::Succeeded]);
    Refund::factory()->forPayment($payment)->create(['amount' => 500, 'status' => RefundStatus::Failed]);
    Refund::factory()->forPayment($payment)->create(['amount' => 500, 'status' => RefundStatus::Cancelled]);

    expect($payment->totalRefundedAmount())->toBe(6000)
        ->and($payment->remainingRefundableAmount())->toBe(4000)
        ->and($payment->totalSuccessfulRefundAmount())->toBe(3000)
        ->and($payment->hasRefunds())->toBeTrue();
});

it('allows successive partial refunds until the balance is fully reserved', function () {
    $payment = refundablePayment(10000);

    createRefund($payment, ['amount' => 3000]);
    createRefund($payment, ['amount' => 2000]);
    createRefund($payment, ['amount' => 5000]);

    expect($payment->remainingRefundableAmount())->toBe(0)
        ->and(fn () => createRefund($payment, ['amount' => 1]))->toThrow(InvalidArgumentException::class)
        ->and(Refund::count())->toBe(3);
});

// ---------------------------------------------------------------------------
// Payment eligibility
// ---------------------------------------------------------------------------

it('only allows refunds of succeeded and partially_refunded payments', function () {
    expect(createRefund(refundablePayment(), ['amount' => 1000])->exists)->toBeTrue();

    $partial = Payment::factory()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'status' => PaymentStatus::PartiallyRefunded,
    ]);
    expect(createRefund($partial, ['amount' => 1000])->exists)->toBeTrue();

    foreach ([PaymentStatus::Pending, PaymentStatus::Processing, PaymentStatus::Failed, PaymentStatus::Cancelled, PaymentStatus::Refunded] as $status) {
        $payment = Payment::factory()->create(['amount' => 5000, 'currency' => 'USD', 'status' => $status]);

        expect(fn () => createRefund($payment, ['amount' => 1000]))->toThrow(InvalidArgumentException::class);
    }

    expect(Refund::count())->toBe(2);
});

// ---------------------------------------------------------------------------
// Currency
// ---------------------------------------------------------------------------

it('defaults the refund currency to the payment currency and normalizes case', function () {
    $payment = refundablePayment(10000, 'PLN');

    expect(createRefund($payment, ['amount' => 1000])->currency)->toBe('PLN')
        ->and(createRefund($payment, ['amount' => 1000, 'currency' => 'pln'])->currency)->toBe('PLN');
});

it('rejects invalid length, invalid characters and mismatched currencies', function () {
    $payment = refundablePayment(10000, 'USD');

    expect(fn () => createRefund($payment, ['amount' => 1000, 'currency' => 'US']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createRefund($payment, ['amount' => 1000, 'currency' => 'USDD']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createRefund($payment, ['amount' => 1000, 'currency' => 'US1']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => createRefund($payment, ['amount' => 1000, 'currency' => 'EUR']))->toThrow(InvalidArgumentException::class)
        ->and(Refund::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Merchant isolation
// ---------------------------------------------------------------------------

it('never accepts merchant identity through refund data', function () {
    $payment = refundablePayment();
    $anotherMerchant = Merchant::factory()->create();

    $refund = createRefund($payment, [
        'amount' => 1000,
        'merchant_id' => $anotherMerchant->id,
    ]);

    expect($refund->merchant_id)->toBe($payment->merchant_id)
        ->and($refund->merchant_id)->not->toBe($anotherMerchant->id)
        ->and($refund->refresh()->merchant_id)->toBe($payment->merchant_id);
});

// ---------------------------------------------------------------------------
// Payment attempt association
// ---------------------------------------------------------------------------

it('accepts a payment attempt belonging to the payment', function () {
    $payment = refundablePayment();
    $attempt = PaymentAttempt::factory()->forPayment($payment)->succeeded()->create();

    $refund = createRefund($payment, ['amount' => 1000, 'payment_attempt_id' => $attempt->id]);

    expect($refund->payment_attempt_id)->toBe($attempt->id)
        ->and($refund->refresh()->paymentAttempt->is($attempt))->toBeTrue();
});

it('rejects attempts from other payments or merchants', function () {
    $payment = refundablePayment();
    $otherPayment = refundablePayment(); // different merchant by default
    $foreignAttempt = PaymentAttempt::factory()->forPayment($otherPayment)->succeeded()->create();

    expect(fn () => createRefund($payment, ['amount' => 1000, 'payment_attempt_id' => $foreignAttempt->id]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => createRefund($payment, ['amount' => 1000, 'payment_attempt_id' => 999999]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => createRefund($payment, ['amount' => 1000, 'payment_attempt_id' => '5']))
        ->toThrow(InvalidArgumentException::class)
        ->and(Refund::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Provider
// ---------------------------------------------------------------------------

it('allows refunds without a provider and normalizes valid provider names', function () {
    $payment = refundablePayment();

    expect(createRefund($payment, ['amount' => 1000])->provider)->toBeNull()
        ->and(createRefund($payment, ['amount' => 1000, 'provider' => 'STRIPE'])->provider)->toBe('stripe')
        ->and(createRefund($payment, ['amount' => 1000, 'provider' => 'PayU'])->provider)->toBe('payu')
        ->and(createRefund($payment, ['amount' => 1000, 'provider' => 'p24'])->provider)->toBe('p24')
        ->and(fn () => createRefund($payment, ['amount' => 1000, 'provider' => 'invalid-provider']))
        ->toThrow(InvalidArgumentException::class)
        ->and(Refund::where('provider', 'invalid-provider')->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Initial state safety
// ---------------------------------------------------------------------------

it('starts every refund as pending without mutating the payment status', function () {
    $payment = refundablePayment(10000);

    $refund = createRefund($payment, [
        'amount' => 5000,
        'reason' => 'Customer request',
        'request_metadata' => ['source' => 'smoke'],
    ]);

    expect($refund->refresh()->status)->toBe(RefundStatus::Pending)
        ->and($refund->reason)->toBe('Customer request')
        ->and($refund->requested_at)->not->toBeNull()
        ->and($refund->completed_at)->toBeNull()
        // The parent payment keeps its status until refund execution and
        // reconciliation later flip it to partially_refunded / refunded.
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});
