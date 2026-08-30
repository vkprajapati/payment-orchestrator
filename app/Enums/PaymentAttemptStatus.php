<?php

namespace App\Enums;

/**
 * Lifecycle states of an individual payment attempt.
 *
 * A PaymentAttempt records one processing pass of a Payment through a
 * specific provider; a Payment may have several attempts (e.g. a failed
 * Stripe try followed by a successful P24 one).
 *
 * Stored as a plain string column (never a native PostgreSQL ENUM) so
 * future versions can introduce additional statuses without a blocking
 * schema migration.
 */
enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Determine whether the attempt has reached a final state and can
     * no longer transition to any other status.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Processing => false,
            self::Succeeded, self::Failed, self::Cancelled => true,
        };
    }

    /**
     * Determine whether the attempt completed successfully at the provider.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }
}
