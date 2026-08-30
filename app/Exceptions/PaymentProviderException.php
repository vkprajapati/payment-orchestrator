<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown for payment-provider-level failures: provider unavailable,
 * missing configuration, invalid provider responses, unsupported
 * operations, or a charge attempt against a not-yet-implemented
 * provider.
 *
 * SECURITY: messages must never contain API secrets, access tokens,
 * private keys, or full raw provider responses — only safe summaries.
 */
final class PaymentProviderException extends Exception
{
    /**
     * Thrown when a provider has not been configured or implemented yet.
     */
    public static function notConfigured(string $provider): self
    {
        return new self("Payment provider [{$provider}] is not configured yet.");
    }

    /**
     * Thrown when an unknown provider name is requested from a manager.
     */
    public static function unknownProvider(string $provider): self
    {
        return new self("Payment provider [{$provider}] is not registered.");
    }

    /**
     * Thrown when a provider does not support the requested operation.
     */
    public static function unsupportedOperation(string $provider, string $operation): self
    {
        return new self("Payment provider [{$provider}] does not support operation [{$operation}].");
    }
}
