<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class ListPaymentsRequest extends FormRequest
{
    /**
     * Authorization happened in the api.key middleware: the request is
     * already bound to an authenticated merchant via ApiRequestContext.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules.
     *
     * Only pagination is configurable — filtering/search is intentionally
     * out of scope. Note there is deliberately no merchant_id rule: the
     * merchant always comes from the API key context and can never be
     * overridden via query parameters.
     *
     * @return array<string, array<int|string>>
     */
    public function rules(): array
    {
        return [
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The page size to use, defaulting to 20 when not provided.
     */
    public function perPage(): int
    {
        return $this->validated('per_page') ?? 20;
    }
}
