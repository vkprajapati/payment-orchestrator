<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Audit Log Configuration
|--------------------------------------------------------------------------
|
| export.max_events — the maximum number of audit events a single export
| request may return. Exports are merchant-scoped and filtered; when more
| events match the request is rejected with a controlled 422 client error
| so the caller can narrow the range. Exports are never silently
| truncated, and the cap prevents unbounded memory consumption.
|
| retention.days — the single retention window underpinning the two-stage
| audit lifecycle:
|   active → archived (soft-deleted) → permanently pruned
| archive runs at 01:00 (audit:archive), prune at 02:00 (audit:prune).
|   - Archive: active events strictly OLDER than the cutoff are archived
|     (deleted_at set). Events exactly AT the cutoff are kept active.
|   - Prune: archived events whose deleted_at (archive time) is strictly
|     OLDER than the cutoff are permanently deleted. Because archival and
|     pruning share the same window and run on the same day, an event
|     archived at 01:00 is never prunable at 02:00 — it must wait a full
|     additional retention window, guaranteeing the archive→prune grace
|     period.
| The default of 365 days is a conservative, compliance-friendly window
| for operational audit logs. Must be an integer >= 1 — invalid values
| fail safely (the actions throw and the commands report a controlled
| error instead of archiving/pruning anything).
|
| retention.batch_size — maximum number of rows processed per batch by the
| archive and prune actions. Bounded batches keep each transaction
| short-lived (each batch commits independently) and memory usage flat.
| Must be >= 1.
|
*/

return [

    'export' => [
        'max_events' => env('AUDIT_EXPORT_MAX_EVENTS', 5000),
    ],

    'retention' => [
        'days' => env('AUDIT_RETENTION_DAYS', 365),
        'batch_size' => env('AUDIT_RETENTION_BATCH_SIZE', 500),
    ],

];
