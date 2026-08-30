<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\PaymentWebhookProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Data\Payments\PaymentProviderWebhookResult;
use App\Enums\PaymentProviderName;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Str;

/**
 * Fully working development/testing provider.
 *
 * Simulates a successful charge and a simple, structurally-verified
 * webhook flow so the provider and webhook architecture can be exercised
 * end-to-end without any real provider integration.
 *
 * Like every provider it is side-effect free: no database writes, no
 * mutation of the Payment model.
 */
class MockPaymentProvider implements PaymentProvider, PaymentWebhookProvider
{
    /**
     * Simulated provider payment id: unique, secure, recognizable.
     */
    public const PAYMENT_ID_PREFIX = 'mock_';

    public function name(): string
    {
        return PaymentProviderName::Mock->value;
    }

    public function charge(Payment $payment, array $data = []): PaymentProviderResult
    {
        // Simulated latency-free success. The payment's own reference is
        // only read for a traceable message — never written back.
        return new PaymentProviderResult(
            success: true,
            provider: $this->name(),
            providerPaymentId: self::PAYMENT_ID_PREFIX.Str::random(24),
            status: PaymentStatus::Succeeded->value,
            message: 'Payment processed successfully',
            metadata: ['reference' => $payment->reference],
        );
    }

    public function supports(string $operation): bool
    {
        return $operation === self::OPERATION_CHARGE;
    }

    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        // Development-mode verification: structural validity only. Real
        // providers will verify cryptographic signatures here.
        return isset($payload['provider_payment_id'], $payload['event'])
            && is_string($payload['provider_payment_id'])
            && $payload['provider_payment_id'] !== ''
            && is_string($payload['event'])
            && $payload['event'] !== '';
    }

    public function parseWebhook(array $payload, array $headers = []): PaymentProviderWebhookResult
    {
        return new PaymentProviderWebhookResult(
            provider: $this->name(),
            providerPaymentId: $payload['provider_payment_id'],
            event: $payload['event'],
            status: isset($payload['status']) && is_string($payload['status'])
                ? $payload['status']
                : null,
            valid: $this->verifyWebhook($payload, $headers),
            metadata: [],
        );
    }
}
