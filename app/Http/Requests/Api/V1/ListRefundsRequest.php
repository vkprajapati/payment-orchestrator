<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentProviderName;
use App\Enums\RefundStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRefundsRequest extends FormRequest
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
     * Normalize input before validation.
     *
     * Provider names are lowercased so lookups are case-insensitive,
     * consistent with PaymentProviderName::normalize().
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('provider')) {
            $this->merge([
                'provider' => strtolower(trim((string) $this->input('provider'))),
            ]);
        }
    }

    /**
     * Validation rules.
     *
     * Only pagination and the safe status/provider filters are configurable.
     * Filtering is intentionally limited — no search/free-text functionality.
     *
     * @return array<string, array<int|string>>
     */
    public function rules(): array
    {
        $statusValues = array_column(RefundStatus::cases(), 'value');
        $providerValues = array_column(PaymentProviderName::cases(), 'value');

        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['nullable', 'string', Rule::in($statusValues)],
            'provider' => ['nullable', 'string', Rule::in($providerValues)],
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
     * The optional status filter (a RefundStatus value), or null.
     */
    public function statusFilter(): ?string
    {
        return $this->validated('status');
    }

    /**
     * The optional provider filter (normalized lowercase name), or null.
     */
    public function providerFilter(): ?string
    {
        return $this->validated('provider');
    }
}
