<?php

namespace App\Data\Payments;

/**
 * Outcome of reconciling a verified provider webhook against a local
 * Refund record.
 *
 * found         — a matching refund existed (provider + provider_refund_id).
 * transitioned  — the refund's status actually changed (non-noop).
 * previousStatus/currentStatus — refund status before/after reconciliation,
 *                 useful for tests and audit logging.
 *
 * Deliberately carries no secrets, provider metadata, database IDs, or
 * merchant information.
 */
final readonly class RefundWebhookReconciliationResult
{
    public function __construct(
        public bool $found,
        public bool $transitioned,
        public ?string $previousStatus = null,
        public ?string $currentStatus = null,
    ) {}
}
