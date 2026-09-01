<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CreateApiKeyRequest extends FormRequest
{
    /**
     * All input is merchant-scoped through ApiRequestContext; no input
     * selects or overrides the tenant. Authorization is satisfied by the
     * presence of an authenticated merchant in the api.key middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ];
    }

    /**
     * Trim whitespace so empty names are rejected by `required`.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::trim((string) $this->input('name')),
            'label' => $this->input('label') !== null
                ? Str::trim((string) $this->input('label'))
                : null,
        ]);
    }

    /**
     * The validated name for the key.
     */
    public function name(): string
    {
        return $this->validated('name');
    }

    /**
     * The validated optional label for the key.
     */
    public function label(): ?string
    {
        return $this->validated('label');
    }

    /**
     * The validated optional expiration date (end of day, UTC).
     */
    public function expiresAt(): ?string
    {
        return $this->validated('expires_at');
    }
}
