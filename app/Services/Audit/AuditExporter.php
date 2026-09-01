<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Exceptions\AuditExportTooLargeException;
use App\Http\Requests\Api\V1\ListAuditEventsRequest;
use App\Http\Resources\Api\V1\AuditEventResource;
use App\Models\AuditEvent;
use App\Models\Merchant;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Merchant-scoped audit log export.
 *
 * Builds on the retrieval API's guarantees: the query always starts from
 * the merchant relation, uses the shared public filter scope, and renders
 * only approved public fields. JSON reuses AuditEventResource so there is
 * exactly one representation of an audit event; CSV carries its own
 * explicit column whitelist and never reads the metadata column at all,
 * so it does not depend on the JSON resource's filtering for safety.
 *
 * Size safety: exports are capped at a config-driven maximum (audit.export.
 * max_events). The cap is enforced with a COUNT before any rows are
 * fetched, so an overly broad request fails fast with a controlled client
 * error instead of silently truncating or exhausting memory. CSV responses
 * stream row-by-row so memory usage stays bounded even at the cap.
 */
class AuditExporter
{
    public const FORMAT_JSON = 'json';

    public const FORMAT_CSV = 'csv';

    /**
     * Explicit CSV column whitelist. Deliberately excludes the metadata
     * column: CSV has no well-defined way to represent nested JSON, so
     * exporting it would risk leaking unapproved keys.
     *
     * @var list<string>
     */
    private const CSV_COLUMNS = [
        'reference',
        'event',
        'outcome',
        'http_method',
        'path',
        'response_status',
        'payment_reference',
        'refund_reference',
        'idempotency_replayed',
        'performed_at',
        'created_at',
    ];

    /**
     * Export the merchant's audit events in the requested format,
     * newest first with a deterministic secondary ordering.
     *
     * @throws AuditExportTooLargeException when the filtered result set
     *                                      exceeds the configured maximum
     */
    public function export(Merchant $merchant, ListAuditEventsRequest $request): Response
    {
        // Start structurally from the merchant relation — the query already
        // carries the tenant constraint before any filter is applied.
        // getQuery() exposes the base query builder BEFORE the SoftDeletes
        // global scope is applied, so the active-row constraint is stated
        // explicitly: archived events are never exported.
        $query = $merchant->auditEvents()
            ->getQuery()
            ->whereNull('audit_events.deleted_at')
            ->filtered(
                $request->eventFilter(),
                $request->outcomeFilter(),
                $request->from(),
                $request->to(),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $max = $this->maxEvents();

        if ($query->count() > $max) {
            throw new AuditExportTooLargeException($max);
        }

        // Race backstop: even if rows are inserted between the COUNT and
        // the actual fetch, the export can never exceed the cap.
        $query->limit($max);

        return $request->exportFormat() === self::FORMAT_CSV
            ? $this->csvResponse($query)
            : $this->jsonResponse($query);
    }

    /**
     * The configured maximum number of events per export.
     */
    public function maxEvents(): int
    {
        return max(1, (int) config('audit.export.max_events', 5000));
    }

    /**
     * JSON export: identical representation to the retrieval API
     * (AuditEventResource), bounded by the size cap.
     */
    private function jsonResponse(Builder $query): Response
    {
        return AuditEventResource::collection(
            $query->get(),
        )->response();
    }

    /**
     * CSV export: streamed so rows are written to the output buffer one
     * at a time instead of accumulating the whole document in memory.
     */
    private function csvResponse(Builder $query): StreamedResponse
    {
        $filename = 'audit-events-export-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $output = fopen('php://output', 'wb');

            // UTF-8 BOM so spreadsheet applications detect the encoding.
            fwrite($output, "\xEF\xBB\xBF");

            fwrite($output, implode(',', self::CSV_COLUMNS)."\n");

            foreach ($query->cursor() as $event) {
                fwrite($output, implode(',', $this->csvRow($event))."\n");
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Map one audit event to its CSV fields — an independent, explicit
     * whitelist that must never rely on the JSON resource's filtering.
     *
     * @return list<string>
     */
    private function csvRow(AuditEvent $event): array
    {
        return [
            $this->csvField($event->reference),
            $this->csvField($event->event->value),
            $this->csvField($event->outcome?->value),
            $this->csvField($event->http_method),
            $this->csvField($event->path),
            $this->csvField($event->response_status),
            $this->csvField($event->payment_reference),
            $this->csvField($event->refund_reference),
            $this->csvField($event->idempotency_replayed ? 'true' : 'false'),
            $this->csvField($event->performed_at?->toISOString()),
            $this->csvField($event->created_at?->toISOString()),
        ];
    }

    /**
     * RFC 4180 field encoding: nulls become empty fields; values
     * containing commas, quotes, or newlines are quoted with inner quotes
     * doubled; UTF-8 bytes pass through untouched.
     */
    private function csvField(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = (string) $value;

        if (preg_match('/[",\r\n]/', $value) === 1) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
