<?php

namespace App\Contracts\Payments;

use App\Data\Payments\PaymentProviderWebhookResult;

/**
 * Contract for providers that can receive webhooks.
 *
 * Deliberately decoupled from HTTP: implementations receive the already
 * extracted payload and headers instead of a Laravel Request, keeping
 * the contract framework-neutral and unit-testable. Controllers own
 * request extraction; providers own verification and parsing only.
 *
 * Implementations must NOT update payments, persist webhooks, or leak
 * secrets in exceptions/messages.
 */
interface PaymentWebhookProvider
{
    /**
     * Verify that the webhook originates from the provider (signature,
     * shared secret, or — for the mock provider — structural validity).
     *
     * @param  array<string, mixed>  $payload  decoded JSON body
     * @param  array<string, mixed>  $headers  request headers (name => value)
     */
    public function verifyWebhook(array $payload, array $headers = []): bool;

    /**
     * Parse a verified webhook into a provider-neutral result.
     *
     * @param  array<string, mixed>  $payload  decoded JSON body
     * @param  array<string, mixed>  $headers  request headers (name => value)
     */
    public function parseWebhook(array $payload, array $headers = []): PaymentProviderWebhookResult;
}
