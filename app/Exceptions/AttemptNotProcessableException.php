<?php

namespace App\Exceptions;

use App\Enums\PaymentAttemptStatus;
use RuntimeException;

class AttemptNotProcessableException extends RuntimeException
{
    /**
     * Raised when the processing engine is asked to process an attempt
     * that is not pending. The message is intentionally generic so no
     * internal state leaks to API consumers.
     */
    public static function forStatus(PaymentAttemptStatus $status): self
    {
        return new self('Payment attempt cannot be processed from its current status.');
    }
}
