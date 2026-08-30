<?php

use App\Actions\Payments\CreatePaymentAttempt;
use App\Actions\Payments\PreparePaymentAttempt;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentProviderName;
use App\Exceptions\PaymentProviderException;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Payments\DefaultPaymentProviderResolver;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('allows a payment to have multiple attempts', function () {
    $payment = Payment::factory()->create();

    PaymentAttempt::factory()->count(3)->forPayment($payment)->create();

    expect($payment->attempts()->count())->toBe(3);
});

it('belongs to a payment', function () {
    $payment = Payment::factory()->create();
    $attempt = PaymentAttempt::factory()->forPayment($payment)->create();

    expect($attempt->payment->is($payment))->toBeTrue();
});

it('belongs to a merchant', function () {
    $payment = Payment::factory()->create();
    $attempt = PaymentAttempt::factory()->forPayment($payment)->create();

    expect($attempt->merchant->is($payment->merchant))->toBeTrue();
});

it('copies the merchant id from the payment, ignoring injected values', function () {
    $payment = Payment::factory()->create();

    $attempt = app(CreatePaymentAttempt::class)->create($payment, PaymentProviderName::Mock);

    expect($attempt->merchant_id)->toBe($payment->merchant_id);
});

it('cannot have an arbitrary merchant id injected', function () {
    $otherMerchant = Merchant::factory()->create();

    // merchant_id is not fillable on PaymentAttempt; mass-assignment
    // attempts are silently ignored, so injected tenant values can
    // never take effect.
    $attempt = new PaymentAttempt([
        'merchant_id' => $otherMerchant->id,
        'provider' => PaymentProviderName::Mock->value,
    ]);

    expect($attempt->merchant_id)->toBeNull();
});

it('copies amount and currency from the payment', function () {
    $payment = Payment::factory()->create(['amount' => 4250, 'currency' => 'PLN']);

    $attempt = app(CreatePaymentAttempt::class)->create($payment, 'mock');

    expect($attempt->amount)->toBe(4250)
        ->and($attempt->currency)->toBe('PLN');
});

it('starts in pending status', function () {
    $payment = Payment::factory()->create();

    $attempt = app(CreatePaymentAttempt::class)->create($payment, PaymentProviderName::Mock);

    expect($attempt->status)->toBe(PaymentAttemptStatus::Pending);
});

it('normalizes provider names', function () {
    $payment = Payment::factory()->create();

    foreach (['MOCK', 'Mock', ' mock '] as $name) {
        $attempt = app(CreatePaymentAttempt::class)->create($payment, $name);

        expect($attempt->provider)->toBe('mock');
    }
});

it('rejects unknown providers', function () {
    $payment = Payment::factory()->create();

    app(CreatePaymentAttempt::class)->create($payment, 'unknown-gateway');
})->throws(PaymentProviderException::class);

it('rejects providers that do not support the charge operation', function () {
    $payment = Payment::factory()->create();

    // Stripe is registered but its real integration does not exist yet,
    // so it does not support the charge operation.
    app(CreatePaymentAttempt::class)->create($payment, 'stripe');
})->throws(PaymentProviderException::class, 'does not support operation [charge]');

it('stores request and response metadata', function () {
    $attempt = PaymentAttempt::factory()->create([
        'request_metadata' => ['ip' => '203.0.113.10'],
        'response_metadata' => ['risk_score' => 12],
    ]);

    expect($attempt->request_metadata)->toBe(['ip' => '203.0.113.10'])
        ->and($attempt->response_metadata)->toBe(['risk_score' => 12]);
});

it('deletes attempts when the payment is deleted', function () {
    $payment = Payment::factory()->create();
    PaymentAttempt::factory()->count(2)->forPayment($payment)->create();

    $payment->delete();

    expect(PaymentAttempt::count())->toBe(0);
});

it('deletes attempts when the merchant is deleted', function () {
    $payment = Payment::factory()->create();
    PaymentAttempt::factory()->count(2)->forPayment($payment)->create();

    $payment->merchant->delete();

    expect(PaymentAttempt::count())->toBe(0);
});

it('exposes terminal and success helpers', function () {
    expect(PaymentAttemptStatus::Pending->isTerminal())->toBeFalse()
        ->and(PaymentAttemptStatus::Processing->isTerminal())->toBeFalse()
        ->and(PaymentAttemptStatus::Succeeded->isTerminal())->toBeTrue()
        ->and(PaymentAttemptStatus::Failed->isTerminal())->toBeTrue()
        ->and(PaymentAttemptStatus::Cancelled->isTerminal())->toBeTrue()
        ->and(PaymentAttemptStatus::Succeeded->isSuccessful())->toBeTrue()
        ->and(PaymentAttemptStatus::Failed->isSuccessful())->toBeFalse();
});

describe('provider resolver', function () {
    it('selects the explicitly requested provider', function () {
        $payment = Payment::factory()->create();

        $provider = (new DefaultPaymentProviderResolver(app(PaymentProviderManager::class)))
            ->resolve($payment, 'p24');

        expect($provider->name())->toBe('p24');
    });

    it('defaults to the mock provider when none is requested', function () {
        $payment = Payment::factory()->create();

        $provider = (new DefaultPaymentProviderResolver(app(PaymentProviderManager::class)))
            ->resolve($payment);

        expect($provider->name())->toBe('mock');
    });

    it('resolves provider names case-insensitively', function () {
        $payment = Payment::factory()->create();
        $resolver = new DefaultPaymentProviderResolver(app(PaymentProviderManager::class));

        expect($resolver->resolve($payment, 'STRIPE')->name())->toBe('stripe')
            ->and($resolver->resolve($payment, 'PayU')->name())->toBe('payu');
    });

    it('rejects unknown providers', function () {
        $payment = Payment::factory()->create();

        (new DefaultPaymentProviderResolver(app(PaymentProviderManager::class)))
            ->resolve($payment, 'not-a-provider');
    })->throws(PaymentProviderException::class);

    it('does not mutate the payment or process it', function () {
        $payment = Payment::factory()->create();
        $before = $payment->only(['status', 'reference']);

        (new PreparePaymentAttempt(
            new DefaultPaymentProviderResolver(app(PaymentProviderManager::class)),
            app(CreatePaymentAttempt::class),
        ))->prepare($payment, 'mock');

        expect($payment->refresh()->only(['status', 'reference']))->toBe($before);
    });
});

it('prepares an attempt through the full pipeline', function () {
    $payment = Payment::factory()->create(['amount' => 2500, 'currency' => 'EUR']);

    $attempt = app(PreparePaymentAttempt::class)->prepare($payment, 'Mock');

    expect($attempt->exists)->toBeTrue()
        ->and($attempt->provider)->toBe('mock')
        ->and($attempt->status)->toBe(PaymentAttemptStatus::Pending)
        ->and($attempt->amount)->toBe(2500)
        ->and($attempt->currency)->toBe('EUR')
        ->and($attempt->merchant_id)->toBe($payment->merchant_id)
        ->and($attempt->payment_id)->toBe($payment->id);
});
