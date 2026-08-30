<?php

use App\Actions\ApiKeys\CreateApiKey;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Create a merchant with a real API key for Authorization headers.
 *
 * @return array{0: Merchant, 1: string}
 */
function executionMerchantWithKey(string $name = 'Execution Merchant'): array
{
    $merchant = Merchant::factory()->create(['name' => $name]);
    $created = app(CreateApiKey::class)->create($merchant, 'CI/CD');

    return [$merchant, $created->rawKey];
}

/**
 * Create a payment for the merchant with a known reference.
 */
function executionPayment(Merchant $merchant, string $reference = 'pay_01EXECUTION000000000001'): Payment
{
    return $merchant->payments()->create([
        'reference' => $reference,
        'amount' => 1050,
        'currency' => 'USD',
    ]);
}

/**
 * Create a pending attempt for the payment via the API.
 */
function executionCreateAttempt(string $rawKey, string $reference): PaymentAttempt
{
    $response = test()->postJson(
        "/api/v1/payments/{$reference}/attempts",
        ['provider' => 'mock'],
        ['Authorization' => "Bearer {$rawKey}"],
    );

    $response->assertCreated();

    return PaymentAttempt::latest('id')->first();
}

/**
 * Execute an attempt via the API.
 */
function executionExecuteAttempt(string $rawKey, string $reference, int $attemptId)
{
    return test()->postJson(
        "/api/v1/payments/{$reference}/attempts/{$attemptId}/execute",
        [],
        ['Authorization' => "Bearer {$rawKey}"],
    );
}

// ---------------------------------------------------------------------------
// Successful execution
// ---------------------------------------------------------------------------

it('executes a pending attempt successfully through the API', function () {
    [$merchant, $rawKey] = executionMerchantWithKey();
    $payment = executionPayment($merchant);
    $attempt = executionCreateAttempt($rawKey, $payment->reference);

    $response = executionExecuteAttempt($rawKey, $payment->reference, $attempt->id);

    $response->assertOk()
        ->assertJsonPath('data.status', 'succeeded')
        ->assertJsonPath('data.provider', 'mock')
        ->assertJsonPath('data.payment_reference', $payment->reference);

    $attempt->refresh();
    expect($attempt->provider_payment_id)->toStartWith('mock_')
        ->and($attempt->completed_at)->not->toBeNull()
        ->and($attempt->status)->toBe(PaymentAttemptStatus::Succeeded);
});

it('marks the parent payment as succeeded when the attempt succeeds', function () {
    [$merchant, $rawKey] = executionMerchantWithKey();
    $payment = executionPayment($merchant);
    $attempt = executionCreateAttempt($rawKey, $payment->reference);

    executionExecuteAttempt($rawKey, $payment->reference, $attempt->id);

    expect($payment->refresh()->status)->toBe(PaymentStatus::Succeeded);
});

it('stores the provider payment id from the mock provider', function () {
    [$merchant, $rawKey] = executionMerchantWithKey();
    $payment = executionPayment($merchant);
    $attempt = executionCreateAttempt($rawKey, $payment->reference);

    $response = executionExecuteAttempt($rawKey, $payment->reference, $attempt->id);

    $response->assertJsonPath('data.provider_payment_id', fn ($id) => str_starts_with($id, 'mock_'));
});

it('stores response metadata from the provider', function () {
    [$merchant, $rawKey] = executionMerchantWithKey();
    $payment = executionPayment($merchant);
    $attempt = executionCreateAttempt($rawKey, $payment->reference);

    executionExecuteAttempt($rawKey, $payment->reference, $attempt->id);

    $attempt->refresh();
    expect($attempt->response_metadata)->toBeArray()
        ->and($attempt->response_metadata['reference'])->toBe($payment->reference);
});

it('sets the started_at and completed_at timestamps', function () {
    [$merchant, $rawKey] = executionMerchantWithKey();
    $payment = executionPayment($merchant);
    $attempt = executionCreateAttempt($rawKey, $payment->reference);

    $response = executionExecuteAttempt($rawKey, $payment->reference, $attempt->id);

    $response->assertJsonPath('data.started_at', fn ($v) => $v !== null)
        ->assertJsonPath('data.completed_at', fn ($v) => $v !== null);
});
