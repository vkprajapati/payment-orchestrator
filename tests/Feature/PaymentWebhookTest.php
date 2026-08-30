<?php

use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\Services\Payments\PaymentWebhookManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('accepts a valid mock webhook through the endpoint', function () {
    $response = $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'mock_abcdef123456',
        'event' => 'payment.succeeded',
        'status' => 'succeeded',
    ]);

    $response->assertOk()->assertExactJson(['received' => true]);
});

it('parses provider payment id event and status from a mock webhook', function () {
    $result = app(PaymentWebhookManager::class)->parse('mock', [
        'provider_payment_id' => 'mock_xyz789',
        'event' => 'payment.succeeded',
        'status' => 'succeeded',
    ]);

    expect($result->provider)->toBe('mock')
        ->and($result->providerPaymentId)->toBe('mock_xyz789')
        ->and($result->event)->toBe('payment.succeeded')
        ->and($result->status)->toBe('succeeded')
        ->and($result->valid)->toBeTrue();
});

it('rejects webhooks for unknown providers without leaking details', function () {
    $this->postJson('/api/v1/webhooks/paypal', [
        'provider_payment_id' => 'x',
        'event' => 'payment.succeeded',
    ])
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.']);
});

it('rejects invalid mock webhooks generically', function (array $payload) {
    $this->postJson('/api/v1/webhooks/mock', $payload)
        ->assertBadRequest()
        ->assertExactJson(['message' => 'Invalid webhook.']);
})->with([
    'missing provider_payment_id' => [['event' => 'payment.succeeded']],
    'missing event' => [['provider_payment_id' => 'mock_1']],
    'empty payload' => [[]],
    'non-string provider_payment_id' => [['provider_payment_id' => 123, 'event' => 'payment.succeeded']],
]);

it('does not require an api key for webhook routes', function () {
    // No Authorization header at all — still accepted when the provider
    // webhook itself verifies.
    $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'mock_nohdr',
        'event' => 'payment.succeeded',
    ])->assertOk();
});

it('does not update payment status from webhooks', function () {
    $merchant = Merchant::factory()->create();
    $payment = Payment::factory()->for($merchant)->create();

    $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'mock_whatever',
        'event' => 'payment.succeeded',
        'status' => 'succeeded',
        'reference' => $payment->reference,
    ])->assertOk();

    $payment->refresh();

    expect($payment->status)->toBe(PaymentStatus::Pending);
});

it('does not create payment records from webhooks', function () {
    $before = Payment::count();

    $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'mock_new',
        'event' => 'payment.succeeded',
        'status' => 'succeeded',
    ])->assertOk();

    expect(Payment::count())->toBe($before);
});

it('keeps webhook routes separate from merchant api.key routes', function () {
    // The webhook endpoint must work without any merchant API context,
    // proving it is outside the api.key middleware group.
    $this->postJson('/api/v1/webhooks/mock', [
        'provider_payment_id' => 'mock_iso',
        'event' => 'payment.succeeded',
    ])->assertOk();

    // And the merchant routes still require authentication.
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
