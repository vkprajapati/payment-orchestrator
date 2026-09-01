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
| retention.days — how long audit events are kept before they become
| eligible for pruning (audit:prune command / daily scheduler entry).
| Events strictly OLDER than the cutoff are removed; events exactly at
| the cutoff are kept. The default of 365 days is a conservative,
| compliance-friendly window for operational audit logs. Must be an
| integer >= 1 — invalid values fail safely (the prune action throws and
| the command reports a controlled error instead of deleting anything).
|
| retention.batch_size — maximum number of rows deleted per batch by the
| pruning action. Bounded batches keep each delete short-lived (each
| batch commits independently) and memory usage flat. Must be >= 1.
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
