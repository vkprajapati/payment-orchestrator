<?php

namespace App\Data\Audit;

use Carbon\CarbonImmutable;

/**
 * Immutable, aggregate-only audit metrics for one merchant.
 *
 * Carries counts and a deterministic time range — never individual audit
 * events, internal identifiers, metadata, or any request/response
 * internals. Every value is derived from database aggregates.
 */
final readonly class AuditMetricsResult
{
    /**
     * @param  int  $total  number of matching audit events
     * @param  array<string, int>  $byEvent  event name (AuditEventName value) => count
     * @param  array<string, int>  $byOutcome  outcome (AuditOutcome value) => count;
     *                                         rows with a NULL outcome are counted in
     *                                         total but never fabricated into a category
     * @param  CarbonImmutable|null  $from  earliest performed_at in the range (null when empty)
     * @param  CarbonImmutable|null  $to  latest performed_at in the range (null when empty)
     */
    public function __construct(
        public int $total,
        public array $byEvent = [],
        public array $byOutcome = [],
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}
}
