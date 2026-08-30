<?php

namespace App\Http\Requests;

use App\Models\ApiKey;
use App\Services\Merchants\CurrentMerchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

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
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Normalize the incoming input before validation.
     *
     * Whitespace is trimmed so empty or whitespace-only names are rejected
     * by the required rule.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::trim((string) $this->input('name')),
        ]);
    }
}
