<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentProvider;
use App\Enums\PaymentProviderName;
use App\Exceptions\PaymentProviderException;

/**
 * Registry for payment providers.
 *
 * Responsibilities ONLY: registration, case-insensitive resolution by
 * name, and listing. The manager deliberately does NOT choose providers,
 * route payments, fail over, or process anything — that belongs to the
 * future routing layer.
 *
 * Not a static/global registry: instances are resolved through the
 * container (singleton per request lifecycle) so tests can build
 * isolated managers freely.
 */
class PaymentProviderManager
{
    /**
     * Registered providers keyed by their normalized name.
     *
     * @var array<string, PaymentProvider>
     */
    private array $providers = [];

    /**
     * Register a provider. Re-registering an already-known provider name
     * REPLACES the previous implementation (last registration wins),
     * which keeps overrides in tests and future config-driven swaps
     * deterministic.
     */
    public function register(PaymentProvider $provider): void
    {
        $this->providers[PaymentProviderName::normalize($provider->name())] = $provider;
    }

    /**
     * Resolve a provider by (case-insensitive) name.
     *
     * @throws PaymentProviderException when the provider is unknown
     */
    public function resolve(string $provider): PaymentProvider
    {
        $key = PaymentProviderName::normalize($provider);

        if (! isset($this->providers[$key])) {
            throw PaymentProviderException::unknownProvider($provider);
        }

        return $this->providers[$key];
    }

    /**
     * All registered providers, keyed by normalized name.
     *
     * @return array<string, PaymentProvider>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    /**
     * Whether a provider with the given name is registered.
     */
    public function has(string $provider): bool
    {
        return isset($this->providers[PaymentProviderName::normalize($provider)]);
    }
}
