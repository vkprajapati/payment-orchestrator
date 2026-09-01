<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ApiKeyScope;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            // Explicit scope allow-list. Duplicates are rejected (not
            // silently normalized) so the merchant's intent is unambiguous.
            // Omitted scopes create a full-access key (Step 11.1
            // compatibility); an explicitly EMPTY array is rejected — a
            // key with no permissions has no legitimate use.
            'scopes' => [
                'nullable', 'array', 'min:1',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value)
                        && count($value) !== count(array_unique($value))) {
                        $fail('The scopes field contains duplicate values.');
                    }
                },
            ],
            'scopes.*' => ['string', Rule::in(ApiKeyScope::values())],
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

    /**
     * The validated optional scope list (null = full access default).
     *
     * @return list<string>|null
     */
    public function scopes(): ?array
    {
        return $this->validated('scopes');
    }
}
