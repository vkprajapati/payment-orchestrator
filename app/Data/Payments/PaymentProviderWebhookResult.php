<?php

namespace App\Data\Payments;

/**
 * Immutable, provider-neutral representation of a parsed webhook event.
 *
 * Carries only normalized, safe data. Raw provider payloads, signatures,
 * and secrets never travel inside this object.
 */
final readonly class PaymentProviderWebhookResult
{
    /**
     * @param  string  $provider  stable provider identifier (e.g. 'mock')
     * @param  string|null  $providerPaymentId  the provider's own payment identifier
     * @param  string  $event  normalized event name (e.g. 'payment.succeeded')
     * @param  string|null  $status  normalized lifecycle status, if the event carries one
     * @param  bool  $valid  whether verification succeeded
     * @param  array<string, mixed>  $metadata  sanitized extra event data
     */
    public function __construct(
        public string $provider,
        public ?string $providerPaymentId,
        public string $event,
        public ?string $status,
        public bool $valid,
        public array $metadata = [],
    ) {}
}
