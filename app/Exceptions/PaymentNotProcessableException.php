<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\PaymentStatus;
use RuntimeException;

/**
 * Raised when the payment router is asked to process a payment that has
 * already reached a terminal state (succeeded, failed, cancelled, ...).
 *
 * The message is intentionally generic so no internal state leaks to API
 * consumers — the controller converts this into a controlled 409.
 */
class PaymentNotProcessableException extends RuntimeException
{
    public static function forPayment(string $reference, PaymentStatus $status): self
    {
        return new self(sprintf(
            'Payment %s cannot be processed from its current status.',
            $reference,
        ));
    }

    public static function terminalState(string $reference, PaymentStatus $status): self
    {
        return new self(sprintf(
            'Payment %s cannot be processed: already %s.',
            $reference,
            $status->value,
        ));
    }
}
