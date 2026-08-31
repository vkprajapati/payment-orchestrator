<?php

namespace App\Exceptions;

use App\Enums\RefundStatus;
use RuntimeException;

class RefundNotProcessableException extends RuntimeException
{
    /**
     * Raised when the refund engine is asked to process a refund that is
     * not pending (e.g. a terminal refund being re-executed). The message
     * is intentionally generic so no internal state leaks to API consumers.
     */
    public static function forStatus(RefundStatus $status): self
    {
        return new self('Refund cannot be processed from its current status.');
    }

    /**
     * Raised when no refund-capable provider can be determined for a
     * refund (e.g. the payment has no successful payment attempt). No
     * alternative provider is silently chosen.
     */
    public static function noProvider(): self
    {
        return new self('No refund-capable provider could be determined for this payment.');
    }
}
