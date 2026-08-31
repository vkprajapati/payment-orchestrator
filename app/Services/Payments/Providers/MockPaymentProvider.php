<?php

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\PaymentWebhookProvider;
use App\Contracts\Payments\RefundProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Data\Payments\PaymentProviderWebhookResult;
use App\Enums\PaymentProviderName;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Support\Str;

/**
 * Fully working development/testing provider.
 *
 * Simulates a successful charge, a deterministic refund flow, and a
 * simple, structurally-verified webhook flow so the provider and webhook
 * architecture can be exercised end-to-end without any real provider
 * integration.
 *
 * Like every provider it is side-effect free: no database writes, no
 * mutation of the Payment model.
 */
class MockPaymentProvider implements PaymentProvider, PaymentWebhookProvider, RefundProvider
{
    /**
     * Simulated provider payment id: unique, secure, recognizable.
     */
    public const PAYMENT_ID_PREFIX = 'mock_';

    /**
     * Simulated provider refund id: unique, secure, recognizable.
     */
    public const REFUND_ID_PREFIX = 'mock_refund_';

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
        return in_array($operation, [self::OPERATION_CHARGE, self::OPERATION_REFUND], true);
    }

    /**
     * Deterministic simulated refund: always succeeds unless the refund's
     * request metadata explicitly forces a failure (mock_refund_should_fail
     * => true), which lets tests exercise the failed path without any HTTP.
     *
     * The refund's request metadata is only ever READ here — the provider
     * never writes to the database or mutates any model.
     */
    public function refund(Payment $payment, PaymentAttempt $attempt, Refund $refund): PaymentProviderResult
    {
        $forceFailure = is_array($refund->request_metadata)
            && (($refund->request_metadata['mock_refund_should_fail'] ?? false) === true);

        if ($forceFailure) {
            return new PaymentProviderResult(
                success: false,
                provider: $this->name(),
                providerPaymentId: null,
                status: RefundStatus::Failed->value,
                message: 'Mock refund failed.',
                failureCode: 'mock_refund_failed',
                metadata: ['reference' => $refund->reference],
            );
        }

        return new PaymentProviderResult(
            success: true,
            provider: $this->name(),
            providerPaymentId: self::REFUND_ID_PREFIX.Str::random(24),
            status: RefundStatus::Succeeded->value,
            message: 'Refund processed successfully',
            metadata: ['reference' => $refund->reference],
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        // Development-mode verification: structural validity only. Real
        // providers will verify cryptographic signatures here. A webhook
        // must carry an event and either a provider payment id (payment
        // events) or a provider refund id (refund events).
        $hasPaymentId = isset($payload['provider_payment_id'])
            && is_string($payload['provider_payment_id'])
            && $payload['provider_payment_id'] !== '';

        $hasRefundId = isset($payload['provider_refund_id'])
            && is_string($payload['provider_refund_id'])
            && $payload['provider_refund_id'] !== '';

        return isset($payload['event'])
            && is_string($payload['event'])
            && $payload['event'] !== ''
            && ($hasPaymentId || $hasRefundId);
    }

    public function parseWebhook(array $payload, array $headers = []): PaymentProviderWebhookResult
    {
        $refundId = $payload['provider_refund_id'] ?? null;

        if (is_string($refundId) && $refundId !== '') {
            // Refund event: identified by the provider refund id. The
            // payment id slot is deliberately left null so payment
            // reconciliation can never accidentally match a refund webhook.
            return new PaymentProviderWebhookResult(
                provider: $this->name(),
                providerPaymentId: null,
                providerRefundId: $refundId,
                event: $payload['event'],
                status: isset($payload['status']) && is_string($payload['status'])
                    ? $payload['status']
                    : null,
                valid: $this->verifyWebhook($payload, $headers),
                metadata: [],
            );
        }

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
