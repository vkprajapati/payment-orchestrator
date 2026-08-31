<?php

namespace App\Enums;

/**
 * Lifecycle of a database-backed API idempotency reservation.
 *
 * Stored as a plain string column (never a native PostgreSQL ENUM) so
 * future states can be introduced without a blocking schema migration.
 */
enum IdempotencyStatus: string
{
    /**
     * The domain operation is in flight. Duplicate deliveries of the same
     * request receive a controlled conflict instead of executing again.
     */
    case Processing = 'processing';

    /**
     * The operation finished and its exact HTTP response is stored for
     * deterministic replay.
     */
    case Completed = 'completed';

    /**
     * Whether the reservation has a stored, replayable response.
     */
    public function isCompleted(): bool
    {
        return $this === self::Completed;
    }
}
