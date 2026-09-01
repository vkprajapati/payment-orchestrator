<?php

namespace App\Http\Requests\Api\V1;

/**
 * Validation for the audit metrics endpoint.
 *
 * Extends ListAuditEventsRequest so the shared filter rules (event,
 * outcome, from, to — enum-whitelisted and date-validated) and the
 * whole-day date normalization are reused verbatim. Filter semantics can
 * therefore never drift from the list and export endpoints.
 *
 * Deliberately no per_page (metrics are not paginated) and no merchant_id
 * rule — the merchant always comes from the API key context and can never
 * be overridden via query parameters.
 */
class GetAuditMetricsRequest extends ListAuditEventsRequest
{
    /**
     * Validation rules: only the shared audit filters.
     *
     * @return array<string, array<int|string>>
     */
    public function rules(): array
    {
        return $this->filterRules();
    }
}
