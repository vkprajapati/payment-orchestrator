<?php

namespace App\Enums;

/**
 * Provider-agnostic payment lifecycle states.
 *
 * Stored as a plain string column in the database (never a native
 * PostgreSQL ENUM) so future versions can introduce additional
 * statuses without a blocking schema migration.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    /**
     * Determine whether the payment has reached a final state and can
     * no longer transition to any other status.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Pending, self::Processing => false,
            self::Succeeded, self::Failed,
            self::Cancelled, self::Refunded, self::PartiallyRefunded => true,
        };
    }
}
