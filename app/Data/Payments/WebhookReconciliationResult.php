<?php

namespace App\Data\Payments;

/**
 * Outcome of reconciling a verified provider webhook against the local
 * PaymentAttempt / Payment records.
 *
 * found         — a matching attempt existed (provider + provider_payment_id).
 * transitioned  — the attempt's status actually changed (non-noop).
 * previousStatus/currentStatus — attempt status before/after reconciliation,
 *                 useful for tests and audit logging.
 */
final readonly class WebhookReconciliationResult
{
    public function __construct(
        public bool $found,
        public bool $transitioned,
        public ?string $previousStatus = null,
        public ?string $currentStatus = null,
    ) {}
}
