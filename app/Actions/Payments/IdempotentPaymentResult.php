<?php

namespace App\Actions\Payments;

use App\Models\Payment;

/**
 * Outcome of an idempotent payment creation.
 *
 * created === true  → a new payment was inserted (HTTP 201).
 * created === false → an existing payment was replayed (HTTP 200).
 */
final readonly class IdempotentPaymentResult
{
    public function __construct(
        public Payment $payment,
        public bool $created,
    ) {}
}
