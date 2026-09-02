<?php

namespace App\Http\Requests;

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CreateApiKeyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The merchant is resolved from the secure CurrentMerchant context,
     * never from request input. Only owners, admins, and developers may
     * create API keys.
     */
    public function authorize(): bool
    {
        $merchant = app(CurrentMerchant::class)->get();

        return $merchant !== null
            && $this->user() !== null
            && $this->user()->can('create', [ApiKey::class, $merchant]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Scopes: omitted scopes mean full access (Step 11.1 compatibility).
     * The creation form always submits a `scopes_submitted` marker with
     * its checkbox group, so when that marker is present the selection is
     * authoritative: an empty or missing selection is rejected instead of
     * silently falling back to full access — a user who deliberately
     * unchecks every scope must never receive a full-access key.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $scopeRules = $this->boolean('scopes_submitted')
            ? ['required', 'array', 'min:1']
            : ['nullable', 'array'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'scopes_submitted' => ['nullable', 'boolean'],
            'scopes' => $scopeRules,
            'scopes.*' => ['required', 'string', 'distinct:strict', Rule::in(ApiKeyScope::values())],
        ];
    }

    /**
     * The validated key name.
     */
    public function name(): string
    {
        return (string) $this->validated('name');
    }

    /**
     * The validated optional label.
     */
    public function label(): ?string
    {
        $label = $this->validated('label');

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * The validated optional expiration date (Y-m-d).
     */
    public function expiresAt(): ?string
    {
        $expiresAt = $this->validated('expires_at');

        return is_string($expiresAt) && $expiresAt !== '' ? $expiresAt : null;
    }

    /**
     * The validated optional scope list; null means full access.
     *
     * @return list<string>|null
     */
    public function scopes(): ?array
    {
        $scopes = $this->validated('scopes');

        if (! is_array($scopes) || $scopes === []) {
            return null;
        }

        return array_values(array_unique($scopes));
    }

    /**
     * Normalize the incoming input before validation.
     *
     * Whitespace is trimmed so empty or whitespace-only names and labels
     * are rejected/normalized consistently.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::trim((string) $this->input('name')),
            'label' => $this->exists('label') ? Str::trim((string) $this->input('label')) : null,
        ]);
    }
}
