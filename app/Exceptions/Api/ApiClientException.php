<?php

namespace App\Exceptions\Api;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Normalized, user-safe error produced by ApiClient for non-2xx or failed
 * API interactions.
 *
 * The message is always a safe generic copy written for the UI; the raw
 * HTTP payload, exception traces, and internal details are never attached.
 * Transport-level failures carry status 0 so callers can distinguish
 * "could not reach the API" from a concrete HTTP response.
 */
final class ApiClientException extends RuntimeException
{
    /**
     * @param  int  $status  HTTP status, or 0 for transport-level failures
     * @param  array<string, mixed>  $validationErrors  field-keyed errors from 422 responses
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $validationErrors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Build a normalized exception from a failed HTTP response.
     */
    public static function fromResponse(Response $response): self
    {
        $status = (int) $response->status();
        $payload = $response->json() ?? [];

        $message = match (true) {
            $status === 401 => 'Authentication failed. Please check your API key.',
            $status === 403 => 'You do not have permission to perform this action.',
            $status === 404 => 'The requested resource was not found.',
            $status === 409 => 'An idempotency conflict occurred. Use a new request or a fresh Idempotency-Key.',
            $status === 422 => (string) ($payload['message'] ?? 'The given data was invalid.'),
            $status === 429 => 'Too many requests. Please try again shortly.',
            $status >= 500 => 'The service is temporarily unavailable. Please try again later.',
            default => 'Something went wrong while contacting the API.',
        };

        return new self(
            message: $message,
            status: $status,
            validationErrors: is_array($payload['errors'] ?? null) ? $payload['errors'] : [],
        );
    }
}
