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
*/

return [

    'export' => [
        'max_events' => env('AUDIT_EXPORT_MAX_EVENTS', 5000),
    ],

];
