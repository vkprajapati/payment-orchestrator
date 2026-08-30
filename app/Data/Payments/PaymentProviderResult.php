<?php

namespace App\Data\Payments;

/**
 * Immutable, provider-neutral outcome of a provider charge attempt.
 *
 * Contains no credentials and no raw provider responses — only the
 * normalized data the orchestrator (and later the router/failover
 * layer) needs to make decisions.
 */
final readonly class PaymentProviderResult
{
    /**
     * @param  bool  $success  whether the provider accepted the charge
     * @param  string  $provider  stable provider identifier (e.g. 'mock')
     * @param  string|null  $providerPaymentId  the provider's own payment identifier
     * @param  string  $status  normalized lifecycle status (a PaymentStatus value)
     * @param  string|null  $message  safe, human-readable summary (never secrets)
     * @param  array<string, mixed>  $metadata  sanitized provider metadata
     */
    public function __construct(
        public bool $success,
        public string $provider,
        public ?string $providerPaymentId,
        public string $status,
        public ?string $message = null,
        public array $metadata = [],
    ) {}
}
