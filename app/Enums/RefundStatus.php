<?php

namespace App\Enums;

/**
 * Provider-agnostic refund lifecycle states.
 *
 * Stored as a plain string column in the database (never a native
 * PostgreSQL ENUM) so future versions can introduce additional
 * statuses without a blocking schema migration.
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Determine whether the refund has reached a final state and can
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
     * Determine whether the refund completed successfully at the provider.
     */
    public function isSuccessful(): bool
    {
        return $this === self::Succeeded;
    }

    /**
     * Determine whether a refund in this status consumes (reserves) the
     * parent payment's refundable balance.
     *
     * This is the reservation model preventing over-refunding: pending,
     * processing and succeeded refunds hold their amount against the
     * payment, while failed and cancelled refunds release it.
     */
    public function reservesBalance(): bool
    {
        return match ($this) {
            self::Pending, self::Processing, self::Succeeded => true,
            self::Failed, self::Cancelled => false,
        };
    }

    /**
     * The database values of every status that reserves refund balance,
     * ready for whereIn() queries.
     *
     * @return list<string>
     */
    public static function balanceReservingValues(): array
    {
        return array_values(array_map(
            static fn (self $status) => $status->value,
            array_values(array_filter(
                self::cases(),
                static fn (self $status) => $status->reservesBalance(),
            )),
        ));
    }
}
