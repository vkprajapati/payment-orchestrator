<?php

use App\Data\Payments\PaymentRoutingPlan;
use App\Models\Payment;
use App\Services\Payments\DefaultPaymentRoutingStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns only the mock provider with the default loadout', function () {
    $payment = Payment::factory()->create();

    $plan = app(DefaultPaymentRoutingStrategy::class)->resolveProviders($payment);

    expect($plan->providers())->toBe(['mock']);
});

it('excludes providers that do not support the charge operation', function () {
    // Stripe/P24/Razorpay/PayU are registered but report
    // supports(charge) === false, so they must never appear in the plan.
    $payment = Payment::factory()->create();

    $plan = app(DefaultPaymentRoutingStrategy::class)->resolveProviders($payment);

    expect($plan->providers())->not->toContain('stripe')
        ->not->toContain('p24')
        ->not->toContain('razorpay')
        ->not->toContain('payu')
        ->toContain('mock');
});

it('produces a deterministic provider order', function () {
    $payment = Payment::factory()->create();
    $strategy = app(DefaultPaymentRoutingStrategy::class);

    $first = $strategy->resolveProviders($payment)->providers();
    $second = $strategy->resolveProviders($payment->fresh())->providers();

    expect($first)->toBe($second);
});

it('returns an immutable routing plan DTO', function () {
    $plan = new PaymentRoutingPlan(providers: ['stripe', 'mock']);

    expect($plan->providers())->toBe(['stripe', 'mock']);

    // The DTO is readonly — mutation attempts must fail.
    $reflection = new ReflectionClass($plan);
    expect($reflection->isReadOnly())->toBeTrue();
});
