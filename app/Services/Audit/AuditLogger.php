<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use App\Models\Merchant;
use Illuminate\Support\Str;

/**
 * Append-only merchant API audit logging.
 *
 * The ONLY place audit_events rows are created, keeping persistence logic
 * out of controllers. Records are always created through the merchant's
 * relation so merchant_id is set server-side (never from request input).
 * Each record receives a public "evt_" + ULID reference so it can be looked
 * up through the retrieval API without exposing the numeric internal id.
 *
 * Deliberate exclusions:
 *   - no API keys, Authorization headers, or raw request bodies
 *   - no provider secrets or raw provider responses
 *   - no internal IDs — only the public pay_/ref_ references (and the evt_
 *     audit reference)
 *   - metadata is filtered against an explicit whitelist
 *
 * The logs are written after the primary operation produced a response,
 * so audit persistence can fail without corrupting domain state (the
 * exception is left to surface via the framework's exception handling;
 * it never rolls back an already-completed payment/refund).
 */
class AuditLogger
{
    /**
     * Whitelisted metadata keys that may be persisted. Anything else is
     * silently dropped — never blindly serialize request data here.
     */
        private const SAFE_METADATA = ['amount', 'currency', 'provider', 'status', 'reason', 'scopes'];

    /**
     * Record a merchant audit event.
     *
     * @param  array<string, mixed>  $metadata  safe, whitelisted metadata
     */
    public function log(
        Merchant $merchant,
        AuditEventName $event,
        string $httpMethod,
        string $path,
        ?AuditOutcome $outcome = null,
        ?int $responseStatus = null,
        ?string $paymentReference = null,
        ?string $refundReference = null,
        bool $replayed = false,
        array $metadata = [],
    ): void {
        $safeMetadata = array_intersect_key($metadata, array_flip(self::SAFE_METADATA));

        $merchant->auditEvents()->create([
            'reference' => 'evt_'.Str::ulid(),
            'event' => $event->value,
            'http_method' => $httpMethod,
            'path' => $path,
            'response_status' => $responseStatus,
            'outcome' => $outcome?->value,
            'payment_reference' => $paymentReference,
            'refund_reference' => $refundReference,
            'idempotency_replayed' => $replayed,
            'metadata' => $safeMetadata === [] ? null : $safeMetadata,
            'performed_at' => now(),
        ]);
    }
}
