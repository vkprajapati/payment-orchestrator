<?php

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\RefundProvider;
use App\Enums\RefundStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Services\Payments\Providers\MockPaymentProvider;
use App\Services\Payments\Providers\PayUPaymentProvider;
use App\Services\Payments\Providers\Przelewy24PaymentProvider;
use App\Services\Payments\Providers\StripePaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Refund as StripeRefund;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Enable Stripe with a test secret key (local copy so this file is
 * runnable standalone; named distinctly from stripeConfigure() used by
 * the Stripe provider tests to avoid redeclaration in full-suite runs).
 */
function stripeRefundConfigure(bool $enabled = true, ?string $key = 'sk_test_fake'): void
{
    config()->set('payments.providers.stripe.enabled', $enabled);
    config()->set('payments.providers.stripe.secret_key', $key ?? '');
}

/**
 * Build a refund execution scenario: a succeeded payment with a successful
 * provider attempt (holding a provider payment id) and a pending refund
 * associated with that attempt.
 *
 * @return array{0: Payment, 1: PaymentAttempt, 2: Refund}
 */
function refundScenario(string $provider = 'mock'): array
{
    $payment = Payment::factory()->succeeded()->create(['amount' => 10000, 'currency' => 'USD']);

    $attempt = PaymentAttempt::factory()->forPayment($payment)->succeeded()->create([
        'provider' => $provider,
        'provider_payment_id' => 'pi_test_123',
    ]);

    $refund = Refund::factory()->forPayment($payment)->create([
        'payment_attempt_id' => $attempt->id,
        'provider' => $provider,
        'amount' => 2500,
    ]);

    return [$payment, $attempt, $refund];
}

/**
 * Build a fake Stripe client whose refunds.create() returns the given
 * refund (or throws). Captures the params passed to create().
 */
function fakeStripeRefundClient(StripeRefund $refund, ?Throwable $throw = null): object
{
    $refunds = new class($refund, $throw)
    {
        public array $lastParams = [];

        public function __construct(public StripeRefund $refund, public ?Throwable $throw) {}

        public function create(array $params = []): StripeRefund
        {
            $this->lastParams = $params;

            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->refund;
        }
    };

    return new class($refunds)
    {
        public function __construct(public object $refunds) {}
    };
}

// ---------------------------------------------------------------------------
// Capability matrix
// ---------------------------------------------------------------------------

it('exposes distinct charge and refund capabilities on the contract', function () {
    expect(PaymentProvider::OPERATION_CHARGE)->toBe('charge')
        ->and(PaymentProvider::OPERATION_REFUND)->toBe('refund')
        ->and(new MockPaymentProvider instanceof RefundProvider)->toBeTrue()
        ->and(new StripePaymentProvider instanceof RefundProvider)->toBeTrue()
        ->and(PaymentProvider::OPERATION_CHARGE)->not->toBe(PaymentProvider::OPERATION_REFUND);
});

it('does not treat PayU or P24 as refund-capable', function () {
    $payu = new PayUPaymentProvider;
    $p24 = new Przelewy24PaymentProvider;

    expect($payu instanceof RefundProvider)->toBeFalse()
        ->and($payu->supports(PaymentProvider::OPERATION_REFUND))->toBeFalse()
        ->and($p24 instanceof RefundProvider)->toBeFalse()
        ->and($p24->supports(PaymentProvider::OPERATION_REFUND))->toBeFalse();
});

it('supports mock refunds regardless of configuration', function () {
    $provider = new MockPaymentProvider;

    expect($provider->supports(PaymentProvider::OPERATION_REFUND))->toBeTrue()
        ->and($provider->supports(PaymentProvider::OPERATION_CHARGE))->toBeTrue();
});

it('supports stripe refunds only when configured', function () {
    $provider = new StripePaymentProvider;

    // Disabled by default in the test environment.
    expect($provider->supports(PaymentProvider::OPERATION_REFUND))->toBeFalse();

    stripeRefundConfigure(true, 'sk_test_fake');
    expect($provider->supports(PaymentProvider::OPERATION_REFUND))->toBeTrue();

    stripeRefundConfigure(true, null);
    expect($provider->supports(PaymentProvider::OPERATION_REFUND))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Mock refund execution
// ---------------------------------------------------------------------------

it('executes deterministic mock refunds with a unique provider refund id', function () {
    [, , $refund] = refundScenario('mock');

    $result = (new MockPaymentProvider)->refund(
        $refund->payment,
        $refund->paymentAttempt,
        $refund,
    );

    expect($result->success)->toBeTrue()
        ->and($result->providerPaymentId)->toStartWith(MockPaymentProvider::REFUND_ID_PREFIX)
        ->and($result->status)->toBe(RefundStatus::Succeeded->value)
        ->and($result->metadata)->not->toBeEmpty();
});

it('allows tests to force a deterministic mock refund failure', function () {
    [, , $refund] = refundScenario('mock');
    $refund->request_metadata = ['mock_refund_should_fail' => true];

    $result = (new MockPaymentProvider)->refund($refund->payment, $refund->paymentAttempt, $refund);

    expect($result->success)->toBeFalse()
        ->and($result->failureCode)->toBe('mock_refund_failed')
        ->and($result->status)->toBe(RefundStatus::Failed->value);
});

// ---------------------------------------------------------------------------
// Stripe refund execution (HTTP faked via the container binding)
// ---------------------------------------------------------------------------

it('creates a Stripe refund against the original payment intent with correct parameters', function () {
    stripeRefundConfigure(true, 'sk_test_fake');
    [$payment, $attempt, $refund] = refundScenario('stripe');

    $stripeRefund = new StripeRefund('re_test_123');
    $stripeRefund->status = StripeRefund::STATUS_SUCCEEDED;

    app()->instance('stripe.client', fakeStripeRefundClient($stripeRefund));

    $result = (new StripePaymentProvider)->refund($payment, $attempt, $refund);

    $fake = app('stripe.client');
    $params = $fake->refunds->lastParams;

    expect($result->success)->toBeTrue()
        ->and($result->providerPaymentId)->toBe('re_test_123')
        ->and($result->status)->toBe(RefundStatus::Succeeded->value)
        ->and($params['payment_intent'])->toBe('pi_test_123')
        ->and($params['amount'])->toBe(2500)
        ->and($params['currency'])->toBe('usd')
        ->and($params['metadata']['internal_reference'])->toBe($payment->reference)
        ->and($params['metadata']['refund_reference'])->toBe($refund->reference);
});

it('maps non-terminal Stripe refund statuses to a controlled failure', function () {
    stripeRefundConfigure(true, 'sk_test_fake');
    [$payment, $attempt, $refund] = refundScenario('stripe');

    $stripeRefund = new StripeRefund('re_test_pending');
    $stripeRefund->status = StripeRefund::STATUS_PENDING;

    app()->instance('stripe.client', fakeStripeRefundClient($stripeRefund));

    $result = (new StripePaymentProvider)->refund($payment, $attempt, $refund);

    expect($result->success)->toBeFalse()
        ->and($result->status)->toBe(RefundStatus::Failed->value)
        ->and($result->failureCode)->toBe('stripe_refund_not_completed')
        // The provider refund id is still captured for reconciliation.
        ->and($result->providerPaymentId)->toBe('re_test_pending');
});

it('wraps Stripe SDK exceptions in a controlled refund failure', function () {
    stripeRefundConfigure(true, 'sk_test_fake');
    [$payment, $attempt, $refund] = refundScenario('stripe');

    app()->instance('stripe.client', fakeStripeRefundClient(
        new StripeRefund('re_test'),
        new RuntimeException('secret_key sk_test_exposed is invalid'),
    ));

    expect(fn () => (new StripePaymentProvider)->refund($payment, $attempt, $refund))
        ->toThrow(PaymentProviderException::class);
});

it('refuses Stripe refunds when the original attempt has no provider payment id', function () {
    stripeRefundConfigure(true, 'sk_test_fake');
    $payment = Payment::factory()->succeeded()->create(['amount' => 10000, 'currency' => 'USD']);

    $attempt = PaymentAttempt::factory()->forPayment($payment)->succeeded()->create([
        'provider' => 'stripe',
        'provider_payment_id' => null,
    ]);

    $refund = Refund::factory()->forPayment($payment)->create([
        'payment_attempt_id' => $attempt->id,
        'provider' => 'stripe',
        'amount' => 2500,
    ]);

    expect(fn () => (new StripePaymentProvider)->refund($payment, $attempt, $refund))
        ->toThrow(PaymentProviderException::class)
        // The fake client is never contacted.
        ->and(app('stripe.client')->refunds->lastParams ?? [])->toBe([]);
});
