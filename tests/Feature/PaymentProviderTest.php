<?php

use App\Contracts\Payments\PaymentProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Enums\PaymentProviderName;
use App\Enums\PaymentStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\Merchant;
use App\Models\Payment;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\Providers\MockPaymentProvider;
use App\Services\Payments\Providers\PayUPaymentProvider;
use App\Services\Payments\Providers\Przelewy24PaymentProvider;
use App\Services\Payments\Providers\RazorpayPaymentProvider;
use App\Services\Payments\Providers\StripePaymentProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Provider manager
// ---------------------------------------------------------------------------

it('resolves the mock provider', function () {
    $manager = app(PaymentProviderManager::class);

    expect($manager->resolve('mock'))->toBeInstanceOf(MockPaymentProvider::class);
});

it('resolves stripe p24 razorpay and payu providers', function (string $name, string $class) {
    expect(app(PaymentProviderManager::class)->resolve($name))->toBeInstanceOf($class);
})->with([
    'stripe' => ['stripe', StripePaymentProvider::class],
    'p24' => ['p24', Przelewy24PaymentProvider::class],
    'razorpay' => ['razorpay', RazorpayPaymentProvider::class],
    'payu' => ['payu', PayUPaymentProvider::class],
]);

it('normalizes provider names when resolving', function () {
    $manager = app(PaymentProviderManager::class);

    expect($manager->resolve('  STRIPE ')->name())->toBe('stripe')
        ->and($manager->resolve('P24')->name())->toBe('p24')
        ->and($manager->resolve('Mock')->name())->toBe('mock');
});

it('rejects unknown providers with a meaningful exception', function () {
    app(PaymentProviderManager::class)->resolve('paypal');
})->throws(PaymentProviderException::class, 'Payment provider [paypal] is not registered.');

it('lists all registered providers', function () {
    $providers = app(PaymentProviderManager::class)->providers();

    expect(array_keys($providers))->toEqualCanonicalizing([
        'mock', 'stripe', 'p24', 'razorpay', 'payu',
    ]);
});

it('replaces a provider on duplicate registration (last registration wins)', function () {
    $manager = new PaymentProviderManager;
    $manager->register(new MockPaymentProvider);
    $replacement = new class implements PaymentProvider
    {
        public function name(): string
        {
            return 'MOCK'; // even normalization applies to replacements
        }

        public function charge(Payment $payment, array $data = []): PaymentProviderResult
        {
            throw new RuntimeException('replaced');
        }

        public function supports(string $operation): bool
        {
            return false;
        }
    };

    $manager->register($replacement);

    expect($manager->resolve('mock'))->toBe($replacement);
});

// ---------------------------------------------------------------------------
// Mock provider
// ---------------------------------------------------------------------------

it('charges successfully through the mock provider', function () {
    $merchant = Merchant::factory()->create();
    $payment = Payment::factory()->for($merchant)->create();

    $result = app(PaymentProviderManager::class)->resolve('mock')->charge($payment);

    expect($result)->toBeInstanceOf(PaymentProviderResult::class)
        ->and($result->success)->toBeTrue()
        ->and($result->provider)->toBe('mock')
        ->and($result->providerPaymentId)->toStartWith('mock_')
        ->and($result->providerPaymentId)->not->toBe('')
        ->and($result->status)->toBe('succeeded')
        ->and($result->message)->toBe('Payment processed successfully');
});

it('generates unique provider payment ids across charges', function () {
    $provider = new MockPaymentProvider;
    $payment = Payment::factory()->create();

    $ids = array_map(
        fn () => $provider->charge($payment)->providerPaymentId,
        range(1, 10),
    );

    expect(count(array_unique($ids)))->toBe(10);
});

it('does not modify the payment model when charging via mock', function () {
    $payment = Payment::factory()->create();

    app(PaymentProviderManager::class)->resolve('mock')->charge($payment);

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->wasChanged())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Placeholder providers
// ---------------------------------------------------------------------------

it('fails safely for not-yet-implemented providers', function (string $name) {
    $payment = Payment::factory()->create();

    app(PaymentProviderManager::class)->resolve($name)->charge($payment);
})->with([
    'stripe' => 'stripe',
    'p24' => 'p24',
    'razorpay' => 'razorpay',
    'payu' => 'payu',
])->throws(PaymentProviderException::class, 'is not configured yet');

it('makes no real http calls and requires no credentials', function () {
    Http::preventStrayRequests();

    $payment = Payment::factory()->create();
    $manager = app(PaymentProviderManager::class);

    foreach (['stripe', 'p24', 'razorpay', 'payu'] as $name) {
        try {
            $manager->resolve($name)->charge($payment);
        } catch (PaymentProviderException) {
            // expected controlled failure
        }
    }

    expect(true)->toBeTrue(); // reaching here means no stray HTTP happened
});

it('exposes stable provider identifiers from the enum', function () {
    expect(array_map(fn ($case) => $case->value, PaymentProviderName::cases()))->toEqualCanonicalizing([
        'stripe', 'p24', 'razorpay', 'payu', 'mock',
    ])
        ->and(PaymentProviderName::isValid('P24'))->toBeTrue()
        ->and(PaymentProviderName::isValid('klarna'))->toBeFalse();
});
