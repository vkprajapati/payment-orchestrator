<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentWebhookProvider;
use App\Enums\PaymentProviderName;
use App\Exceptions\PaymentProviderException;

/**
 * Registry/delegation layer for webhook-capable providers.
 *
 * Verifies and parses provider webhooks through the PaymentWebhookProvider
 * contract, but performs NO state changes: no payment status updates, no
 * webhook persistence, no database writes. Consumers own all downstream
 * effects (a future webhook handler step).
 */
class PaymentWebhookManager
{
    /**
     * Webhook-capable providers keyed by normalized name.
     *
     * @var array<string, PaymentWebhookProvider>
     */
    private array $providers = [];

    /**
     * Register a webhook-capable provider. Re-registering a known name
     * replaces the previous implementation (last registration wins).
     */
    public function register(PaymentWebhookProvider $provider): void
    {
        $this->providers[PaymentProviderName::normalize($provider->name())] = $provider;
    }

    /**
     * Resolve a webhook-capable provider by (case-insensitive) name.
     *
     * @throws PaymentProviderException when the provider is unknown
     */
    public function resolve(string $provider): PaymentWebhookProvider
    {
        $key = PaymentProviderName::normalize($provider);

        if (! isset($this->providers[$key])) {
            throw PaymentProviderException::unknownProvider($provider);
        }

        return $this->providers[$key];
    }

    /**
     * Verify a webhook for the given provider.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function verify(string $provider, array $payload, array $headers = []): bool
    {
        return $this->resolve($provider)->verifyWebhook($payload, $headers);
    }

    /**
     * Parse a (verified) webhook into a provider-neutral result.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $headers
     */
    public function parse(string $provider, array $payload, array $headers = []): mixed
    {
        return $this->resolve($provider)->parseWebhook($payload, $headers);
    }
}
