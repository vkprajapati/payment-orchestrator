<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Centralized names for merchant API audit events.
 *
 * Add new events here (no schema change required) rather than scattering
 * arbitrary strings through controllers. The value is what is persisted in
 * the audit_events.event column.
 */
enum AuditEventName: string
{
    case PaymentCreated = 'payment.created';
    case PaymentProcessingRequested = 'payment.processing_requested';
    case RefundCreated = 'refund.created';
}
