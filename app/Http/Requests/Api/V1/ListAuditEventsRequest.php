<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAuditEventsRequest extends FormRequest
{
    /**
     * Authorization happened in the api.key middleware: the request is
     * already bound to an authenticated merchant via ApiRequestContext.
     *
     * Note: there is deliberately no merchant_id rule — the merchant always
     * comes from the API key context and can never be overridden via query
     * parameters.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize date-only boundaries into inclusive day ranges: a bare
     * Y-m-d `from` starts at 00:00:00 and a bare Y-m-d `to` ends at
     * 23:59:59, so filtering "today" includes the whole day.
     */
    protected function prepareForValidation(): void
    {
        foreach (['from' => '00:00:00', 'to' => '23:59:59'] as $field => $time) {
            $value = $this->input($field);

            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                $this->merge([$field => "{$value} {$time}"]);
            }
        }
    }

    /**
     * Validation rules.
     *
     * Only pagination and the controlled event/outcome/date filters are
     * configurable. Filter values must match the stored enum values exactly;
     * anything else is rejected with 422. There is no search/free-text.
     *
     * @return array<string, array<int|string>>
     */
    public function rules(): array
    {
        return array_merge([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], $this->filterRules());
    }

    /**
     * The shared filter rules used by both the list and the export
     * endpoint (the export request extends this one and reuses them
     * verbatim, so filter semantics can never drift apart).
     *
     * @return array<string, array<int|string>>
     */
    public function filterRules(): array
    {
        $eventValues = array_column(AuditEventName::cases(), 'value');
        $outcomeValues = array_column(AuditOutcome::cases(), 'value');

        return [
            'event' => ['nullable', 'string', Rule::in($eventValues)],
            'outcome' => ['nullable', 'string', Rule::in($outcomeValues)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }

    /**
     * The page size to use, defaulting to 20 when not provided.
     */
    public function perPage(): int
    {
        return $this->validated('per_page') ?? 20;
    }

    /**
     * The optional event filter (an AuditEventName value), or null.
     */
    public function eventFilter(): ?string
    {
        return $this->validated('event');
    }

    /**
     * The optional outcome filter (an AuditOutcome value), or null.
     */
    public function outcomeFilter(): ?string
    {
        return $this->validated('outcome');
    }

    /**
     * The optional performed_at lower boundary, or null.
     */
    public function from(): ?string
    {
        return $this->validated('from');
    }

    /**
     * The optional performed_at upper boundary, or null.
     */
    public function to(): ?string
    {
        return $this->validated('to');
    }
}
