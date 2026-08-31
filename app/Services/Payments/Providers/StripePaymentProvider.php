<?php

declare(strict_types=1);

namespace App\Services\Payments\Providers;

use App\Contracts\Payments\PaymentProvider;
use App\Contracts\Payments\PaymentWebhookProvider;
use App\Contracts\Payments\RefundProvider;
use App\Data\Payments\PaymentProviderResult;
use App\Data\Payments\PaymentProviderWebhookResult;
use App\Enums\PaymentProviderName;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Exceptions\PaymentProviderException;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use Illuminate\Support\Facades\App;
use Stripe\PaymentIntent;
use Stripe\Refund as StripeRefund;
use Stripe\WebhookSignature;
use Throwable;

/**
 * Stripe provider — real PaymentIntents integration.
 *
 * SDK usage is isolated to this class. The provider is a pure consumer of
 * the internal Payment domain: it never persists and never mutates models;
 * the caller owns all state transitions.
 *
 * SECURITY: no credentials, client secrets, or raw API responses ever leave
 * this class. Provider metadata stored on attempts is whitelisted to safe
 * fields only.
 */
class StripePaymentProvider implements PaymentProvider, PaymentWebhookProvider, RefundProvider
{
    public function name(): string
    {
        return PaymentProviderName::Stripe->value;
    }

    /**
     * Charge and refund are available only when the provider is enabled AND
     * a secret key is configured. This capability gate is what routing,
     * attempt creation, and refund execution rely on — a disabled Stripe
     * never enters a routing plan, is never charged, and never refunds.
     */
    public function supports(string $operation): bool
    {
        return in_array($operation, [self::OPERATION_CHARGE, self::OPERATION_REFUND], true)
            && $this->isConfigured();
    }

    /**
     * Create a Stripe PaymentIntent and map the outcome into the
     * provider-neutral result.
     *
     * Only a truly succeeded intent is reported as success. Intents that
     * require further customer/provider action return a controlled
     * non-success result with a meaningful provider status — they are
     * never mislabelled as succeeded.
     *
     * @param  array<string, mixed>  $data  provider-neutral, optional extra data
     */
    public function charge(Payment $payment, array $data = []): PaymentProviderResult
    {
        if (! $this->isConfigured()) {
            throw PaymentProviderException::notConfigured($this->name());
        }

        try {
            $intent = $this->intentsService()->create($this->buildIntentParams($payment, $data));
        } catch (Throwable $exception) {
            // Log only a safe summary; the raw SDK exception may contain
            // credentials or internal details and is never surfaced.
            logger()->warning('Stripe charge failed', [
                'provider' => $this->name(),
                'reference' => $payment->reference,
                'exception' => get_class($exception),
            ]);

            throw PaymentProviderException::chargeFailed($this->name());
        }

        if (! $intent instanceof PaymentIntent) {
            throw PaymentProviderException::chargeFailed($this->name());
        }

        return $this->mapIntent($intent);
    }

    /**
     * Create a Stripe refund against the ORIGINAL PaymentIntent that
     * charged the payment, and map the outcome into the provider-neutral
     * result.
     *
     * The refund is only issued against the provider payment id captured
     * on the successful attempt — a Stripe payment is refunded through
     * Stripe itself, never through another provider.
     *
     * Only a conclusively succeeded refund is reported as success. Any
     * non-terminal Stripe refund status (pending/processing/failed/
     * canceled) is a controlled non-success: refund webhook reconciliation
     * is a later step, so a not-yet-completed refund must never be
     * mislabelled as succeeded. A failed execution releases the refund
     * reservation and the merchant may safely retry.
     *
     * @param  array<string, mixed>  $data  unused, kept for symmetry
     */
    public function refund(Payment $payment, PaymentAttempt $attempt, Refund $refund): PaymentProviderResult
    {
        if (! $this->isConfigured()) {
            throw PaymentProviderException::notConfigured($this->name());
        }

        $paymentIntentId = $attempt->provider_payment_id;

        if (! is_string($paymentIntentId) || $paymentIntentId === '') {
            logger()->warning('Stripe refund rejected: original attempt has no provider payment id', [
                'provider' => $this->name(),
                'reference' => $payment->reference,
            ]);

            throw PaymentProviderException::refundFailed($this->name());
        }

        try {
            $stripeRefund = $this->refundsService()->create([
                'payment_intent' => $paymentIntentId,
                'amount' => $refund->amount,
                'currency' => strtolower($refund->currency),
                'metadata' => [
                    'internal_reference' => $payment->reference,
                    'refund_reference' => $refund->reference,
                ],
            ]);
        } catch (Throwable $exception) {
            // Log only a safe summary; the raw SDK exception may contain
            // credentials or internal details and is never surfaced.
            logger()->warning('Stripe refund failed', [
                'provider' => $this->name(),
                'reference' => $payment->reference,
                'exception' => get_class($exception),
            ]);

            throw PaymentProviderException::refundFailed($this->name());
        }

        if (! $stripeRefund instanceof StripeRefund) {
            throw PaymentProviderException::refundFailed($this->name());
        }

        return $this->mapRefund($stripeRefund);
    }

    /**
     * Verify the Stripe signature over the raw request body using the
     * configured webhook secret.
     *
     * @param  array<string, mixed>  $payload  decoded JSON body
     * @param  array<string, mixed>  $headers  request headers (name => value)
     */
    public function verifyWebhook(array $payload, array $headers = []): bool
    {
        $secret = config('payments.providers.stripe.webhook_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $signature = $headers['stripe-signature'] ?? null;
        $rawBody = $headers['raw_body'] ?? null;

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        // Prefer the raw body (exact bytes Stripe signed); fall back to a
        // deterministic re-encode for direct calls that skip the HTTP layer.
        $body = (is_string($rawBody) && $rawBody !== '') ? $rawBody : json_encode($payload);

        if ($body === false) {
            return false;
        }

        try {
            WebhookSignature::verifyHeader($body, $signature, $secret);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Parse a verified Stripe webhook event into the provider-neutral
     * result. Never mutates payments; the caller owns all downstream
     * effects.
     *
     * @param  array<string, mixed>  $payload  decoded JSON body
     * @param  array<string, mixed>  $headers  request headers (name => value)
     */
    public function parseWebhook(array $payload, array $headers = []): PaymentProviderWebhookResult
    {
        $type = $payload['type'] ?? null;
        $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
        $intentId = is_string($object['id'] ?? null) ? $object['id'] : null;

        if (! is_string($type)) {
            return new PaymentProviderWebhookResult(
                provider: $this->name(),
                providerPaymentId: null,
                event: 'unknown',
                status: null,
                valid: false,
                metadata: [],
            );
        }

        return match ($type) {
            'payment_intent.succeeded' => new PaymentProviderWebhookResult(
                provider: $this->name(),
                providerPaymentId: $intentId,
                event: 'payment.succeeded',
                status: PaymentStatus::Succeeded->value,
                valid: true,
                metadata: $this->safeObjectMetadata($object),
            ),
            'payment_intent.payment_failed' => new PaymentProviderWebhookResult(
                provider: $this->name(),
                providerPaymentId: $intentId,
                event: 'payment.failed',
                status: PaymentStatus::Failed->value,
                valid: true,
                metadata: $this->safeObjectMetadata($object),
            ),
            default => new PaymentProviderWebhookResult(
                provider: $this->name(),
                providerPaymentId: $intentId,
                event: $type,
                status: null,
                valid: true,
                metadata: [],
            ),
        };
    }

    /**
     * Determine whether the provider is enabled and has its secret key.
     */
    private function isConfigured(): bool
    {
        $provider = config('payments.providers.stripe');

        return ($provider['enabled'] ?? false) === true
            && is_string($provider['secret_key'] ?? null)
            && $provider['secret_key'] !== '';
    }

    /**
     * Resolve the PaymentIntents service.
     *
     * The client is resolved through the container so tests can swap a
     * fake (the structural check accepts any object exposing a
     * paymentIntents service) — the production binding builds a real
     * StripeClient from config.
     */
    private function intentsService(): object
    {
        $client = App::make('stripe.client');

        if (! is_object($client) || ! property_exists($client, 'paymentIntents')) {
            throw PaymentProviderException::notConfigured($this->name());
        }

        return $client->paymentIntents;
    }

    /**
     * Resolve the Refunds service.
     *
     * The client is resolved through the container so tests can swap a
     * fake (the structural check accepts any object exposing a refunds
     * service) — the production binding builds a real StripeClient from
     * config.
     */
    private function refundsService(): object
    {
        $client = App::make('stripe.client');

        if (! is_object($client) || ! property_exists($client, 'refunds')) {
            throw PaymentProviderException::notConfigured($this->name());
        }

        return $client->refunds;
    }

    /**
     * Map a Stripe Refund into a provider-neutral result.
     */
    private function mapRefund(StripeRefund $stripeRefund): PaymentProviderResult
    {
        if ($stripeRefund->status === StripeRefund::STATUS_SUCCEEDED) {
            return new PaymentProviderResult(
                success: true,
                provider: $this->name(),
                providerPaymentId: $stripeRefund->id,
                status: RefundStatus::Succeeded->value,
                message: 'Stripe refund succeeded.',
                metadata: ['status' => $stripeRefund->status],
            );
        }

        return new PaymentProviderResult(
            success: false,
            provider: $this->name(),
            providerPaymentId: is_string($stripeRefund->id) ? $stripeRefund->id : null,
            status: RefundStatus::Failed->value,
            message: 'Stripe refund did not complete.',
            failureCode: 'stripe_refund_not_completed',
            metadata: ['status' => $stripeRefund->status],
        );
    }

    /**
     * Build PaymentIntent parameters. The amount stays in the smallest
     * currency unit; the currency is lowercased for Stripe; the internal
     * payment reference is attached as metadata for reconciliation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function buildIntentParams(Payment $payment, array $data = []): array
    {
        $params = [
            'amount' => $payment->amount,
            'currency' => strtolower($payment->currency),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => [
                'internal_reference' => $payment->reference,
            ],
        ];

        if (is_string($payment->description) && $payment->description !== '') {
            $params['description'] = $payment->description;
        }

        // Merge safe scalar caller metadata (never credentials).
        foreach (($data['metadata'] ?? []) as $key => $value) {
            if (is_scalar($value) && is_string($key)) {
                $params['metadata'][$key] = (string) $value;
            }
        }

        return $params;
    }

    /**
     * Map a Stripe PaymentIntent into a provider-neutral result.
     */
    private function mapIntent(PaymentIntent $intent): PaymentProviderResult
    {
        $status = $intent->status;

        if ($status === PaymentIntent::STATUS_SUCCEEDED) {
            return new PaymentProviderResult(
                success: true,
                provider: $this->name(),
                providerPaymentId: $intent->id,
                status: PaymentStatus::Succeeded->value,
                message: 'Stripe payment succeeded.',
                metadata: ['status' => $status],
            );
        }

        $requiresAction = in_array($status, [
            PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD,
            PaymentIntent::STATUS_REQUIRES_CONFIRMATION,
            PaymentIntent::STATUS_REQUIRES_ACTION,
            PaymentIntent::STATUS_REQUIRES_CAPTURE,
            PaymentIntent::STATUS_PROCESSING,
        ], true);

        return new PaymentProviderResult(
            success: false,
            provider: $this->name(),
            providerPaymentId: is_string($intent->id) ? $intent->id : null,
            status: is_string($status) ? $status : PaymentStatus::Failed->value,
            message: $requiresAction ? 'Stripe payment requires further action.' : 'Stripe payment failed.',
            failureCode: $requiresAction ? 'stripe_requires_action' : 'stripe_payment_failed',
            metadata: ['status' => $status],
        );
    }

    /**
     * Whitelist only safe scalar fields from a Stripe webhook object.
     *
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function safeObjectMetadata(array $object): array
    {
        $safe = [];

        foreach (['amount', 'currency', 'status', 'payment_method'] as $key) {
            $value = $object[$key] ?? null;

            if (is_string($value) || is_int($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
