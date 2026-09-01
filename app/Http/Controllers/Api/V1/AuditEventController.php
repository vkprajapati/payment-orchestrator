<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Audit\GetAuditHealth;
use App\Actions\Audit\GetAuditMetrics;
use App\Exceptions\AuditExportTooLargeException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ExportAuditEventsRequest;
use App\Http\Requests\Api\V1\GetAuditMetricsRequest;
use App\Http\Requests\Api\V1\ListAuditEventsRequest;
use App\Http\Resources\Api\V1\AuditEventResource;
use App\Http\Resources\Api\V1\AuditHealthResource;
use App\Http\Resources\Api\V1\AuditMetricsResource;
use App\Models\Merchant;
use App\Services\ApiKeys\ApiRequestContext;
use App\Services\Audit\AuditExporter;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AuditEventController extends Controller
{
    /**
     * List the authenticated merchant's audit events, newest first.
     *
     * Isolation happens at the database level: the query starts from the
     * merchant relation, so no other merchant's rows can ever match. Only
     * the controlled event/outcome/date filters are applied — the merchant
     * is never an input. The secondary id DESC ordering keeps results
     * deterministic when created_at timestamps are identical.
     */
    public function index(
        ListAuditEventsRequest $request,
        ApiRequestContext $context,
    ): AnonymousResourceCollection {
        $merchant = $this->authenticatedMerchant($context);

        $query = $merchant->auditEvents()
            ->filtered(
                $request->eventFilter(),
                $request->outcomeFilter(),
                $request->from(),
                $request->to(),
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return AuditEventResource::collection($query->paginate($request->perPage()));
    }

    /**
     * Export the authenticated merchant's audit events as JSON or CSV.
     *
     * The controller stays thin: merchant resolution, shared filter
     * semantics (via the AuditEvent::filtered scope), then the exporter
     * service owns size protection and safe rendering. Reads never create
     * audit events — no recursion.
     */
    public function export(
        ExportAuditEventsRequest $request,
        ApiRequestContext $context,
        AuditExporter $exporter,
    ): Response {
        $merchant = $this->authenticatedMerchant($context);

        try {
            return $exporter->export($merchant, $request);
        } catch (AuditExportTooLargeException $exception) {
            // Controlled client error — no partial export, no internal
            // row counts, just guidance to narrow the range.
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    /**
     * Aggregate operational metrics for the authenticated merchant's
     * audit events.
     *
     * Thin: merchant resolution, then the GetAuditMetrics action owns the
     * aggregate queries (database count/group-by/min-max only — no row
     * hydration) and the shared filtered scope keeps semantics identical
     * to list and export. Reads never create audit events — no recursion.
     */
    public function metrics(
        GetAuditMetricsRequest $request,
        ApiRequestContext $context,
        GetAuditMetrics $action,
    ): AuditMetricsResource {
        $merchant = $this->authenticatedMerchant($context);

        return new AuditMetricsResource($action->execute($merchant, $request));
    }

    /**
     * Operational health of the audit subsystem (global, aggregate-only).
     *
     * Authentication is still required (api.key), but the returned health
     * state is deliberately merchant-AGNOSTIC: coarse operational status
     * (retention validity, stale count, newest event age) with no
     * merchant identifiers or audit contents — so any authenticated
     * caller sees the same safe operational signal. Reads never create
     * audit events — no recursion.
     */
    public function health(ApiRequestContext $context, GetAuditHealth $action): AuditHealthResource
    {
        $this->authenticatedMerchant($context);

        return new AuditHealthResource($action->execute());
    }

    /**
     * Retrieve a single audit event of the authenticated merchant by its
     * public reference.
     *
     * The lookup is merchant-scoped via the reference, so an unknown
     * reference and a reference owned by another merchant indistinguishably
     * result in 404 — event existence is never revealed across merchants.
     */
    public function show(string $reference, ApiRequestContext $context): AuditEventResource
    {
        $merchant = $this->authenticatedMerchant($context);

        $event = $merchant->auditEvents()
            ->where('reference', $reference)
            ->first();

        if ($event === null) {
            abort(response()->json(['message' => 'Not found.'], 404));
        }

        return new AuditEventResource($event);
    }

    /**
     * Resolve the merchant authenticated by the API key.
     *
     * Defensive: the api.key middleware guarantees a merchant, but a
     * missing context must never fall through to an accidental data leak.
     */
    private function authenticatedMerchant(ApiRequestContext $context): Merchant
    {
        $merchant = $context->merchant();

        if ($merchant === null) {
            abort(401, 'Invalid API key.');
        }

        return $merchant;
    }
}
