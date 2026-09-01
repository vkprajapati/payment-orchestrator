<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Services\Audit\AuditExporter;
use Illuminate\Validation\Rule;

class ExportAuditEventsRequest extends ListAuditEventsRequest
{
    /**
     * Validation rules: the shared audit filter rules plus the export
     * format. Pagination does not apply to exports. There is deliberately
     * no merchant_id rule — the scope always comes from the API key
     * context and can never be overridden via query parameters.
     *
     * @return array<string, array<int|string>>
     */
    public function rules(): array
    {
        return array_merge(parent::filterRules(), [
            'format' => [
                'nullable',
                'string',
                Rule::in([AuditExporter::FORMAT_JSON, AuditExporter::FORMAT_CSV]),
            ],
        ]);
    }

    /**
     * The requested export format, defaulting to json. (Named
     * exportFormat to avoid clashing with Illuminate\Http\Request::format.)
     */
    public function exportFormat(): string
    {
        return $this->validated('format') ?? AuditExporter::FORMAT_JSON;
    }
}
