<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAuditEventsRequest;
use App\Http\Resources\Api\V1\AuditEventResource;
use App\Models\Merchant;
use App\Services\ApiKeys\ApiRequestContext;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->eventFilter() !== null) {
            $query->where('event', $request->eventFilter());
        }

        if ($request->outcomeFilter() !== null) {
            $query->where('outcome', $request->outcomeFilter());
        }

        if ($request->from() !== null) {
            $query->where('performed_at', '>=', $request->from());
        }

        if ($request->to() !== null) {
            $query->where('performed_at', '<=', $request->to());
        }

        return AuditEventResource::collection($query->paginate($request->perPage()));
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
