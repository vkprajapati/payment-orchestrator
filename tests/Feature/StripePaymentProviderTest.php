<?php

use App\Contracts\Payments\PaymentProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Services\Payments\Providers\StripePaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\PaymentIntent;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Enable Stripe with a test secret key.
 */
function stripeConfigure(bool $enabled = true, ?string $key = 'sk_test_fake', ?string $webhookSecret = null): void
{
    config()->set('payments.providers.stripe.enabled', $enabled);
    config()->set('payments.providers.stripe.secret_key', $key ?? '');

    if ($webhookSecret !== null) {
        config()->set('payments.providers.stripe.webhook_secret', $webhookSecret);
    }
}

/**
 * Build a fake Stripe client whose paymentIntents.create() returns the
 * given intent (or throws). Captures the params passed to create().
 *
 * @return object{paymentIntents: object}
 */
function fakeStripeClient(PaymentIntent $intent, ?Throwable $throw = null): object
{
    $intents = new class($intent, $throw)
    {
        public array $lastParams = [];

        public function __construct(public PaymentIntent $intent, public ?Throwable $throw) {}

        public function create(array $params = []): PaymentIntent
        {
            $this->lastParams = $params;

            if ($this->throw !== null) {
                throw $this->throw;
            }

            return $this->intent;
        }
    };

    return new class($intents)
    {
        public function __construct(public object $paymentIntents) {}
    };
}

// ---------------------------------------------------------------------------
// Configuration / capability
// ---------------------------------------------------------------------------

it('does not support charge when disabled', function () {
    stripeConfigure(false);

    expect(app(StripePaymentProvider::class)->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('does not support charge when enabled but the secret key is missing', function () {
    stripeConfigure(true, null);

    expect(app(StripePaymentProvider::class)->supports(PaymentProvider::OPERATION_CHARGE))->toBeFalse();
});

it('supports charge only when enabled and configured', function () {
    stripeConfigure();

    expect(app(StripePaymentProvider::class)->supports(PaymentProvider::OPERATION_CHARGE))->toBeTrue()
        // Step 9.2: configured Stripe is refund-capable too — but unknown
        // operations remain unsupported.
        ->and(app(StripePaymentProvider::class)->supports(PaymentProvider::OPERATION_REFUND))->toBeTrue()
        ->and(app(StripePaymentProvider::class)->supports('unknown-operation'))->toBeFalse();
});

it('exposes the stable stripe identifier', function () {
    expect(app(StripePaymentProvider::class)->name())->toBe('stripe');
});

it('charges fail in a controlled way when stripe is not configured', function () {
    $payment = Payment::factory()->create();

    app(StripePaymentProvider::class)->charge($payment);
})->throws(PaymentProviderException::class, 'is not configured yet');

// ---------------------------------------------------------------------------
// PaymentIntent mapping
// ---------------------------------------------------------------------------

it('maps a succeeded PaymentIntent to a successful result', function () {
    stripeConfigure();
    $payment = Payment::factory()->create();

    app()->instance('stripe.client', fakeStripeClient(
        PaymentIntent::constructFrom(['id' => 'pi_test_succeeded', 'status' => PaymentIntent::STATUS_SUCCEEDED]),
    ));

    $result = app(StripePaymentProvider::class)->charge($payment);

    expect($result)->toBeInstanceOf(PaymentProviderResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->provider)->toBe('stripe')
        ->and($result->providerPaymentId)->toBe('pi_test_succeeded')
        ->and($result->status)->toBe(PaymentStatus::Succeeded->value);
});

it('passes amount and lowercase currency to the Stripe PaymentIntent', function () {
    stripeConfigure();
    $payment = Payment::factory()->create(['amount' => 1050, 'currency' => 'USD', 'reference' => 'pay_01TESTSTRIPE000001']);

    $client = fakeStripeClient(
        PaymentIntent::constructFrom(['id' => 'pi_x', 'status' => PaymentIntent::STATUS_SUCCEEDED]),
    );
    app()->instance('stripe.client', $client);

    app(StripePaymentProvider::class)->charge($payment);

    $params = $client->paymentIntents->lastParams;

    expect($params['amount'])->toBe(1050)
        ->and($params['currency'])->toBe('usd')
        ->and($params['metadata']['internal_reference'])->toBe('pay_01TESTSTRIPE000001');
});

it('attaches safe caller metadata to the PaymentIntent', function () {
    stripeConfigure();
    $payment = Payment::factory()->create();

    $client = fakeStripeClient(
        PaymentIntent::constructFrom(['id' => 'pi_x', 'status' => PaymentIntent::STATUS_SUCCEEDED]),
    );
    app()->instance('stripe.client', $client);

    app(StripePaymentProvider::class)->charge($payment, ['metadata' => ['order_id' => 'ORD-1']]);

    expect($client->paymentIntents->lastParams['metadata']['order_id'])->toBe('ORD-1');
});
