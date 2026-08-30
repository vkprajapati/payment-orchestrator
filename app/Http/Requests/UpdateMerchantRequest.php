<?php

namespace App\Http\Requests;

use App\Services\Merchants\CurrentMerchant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateMerchantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The merchant is resolved from the secure CurrentMerchant context, never
     * from request input. Only owners and admins may update workspace settings.
     */
    public function authorize(): bool
    {
        $merchant = app(CurrentMerchant::class)->get();

        return $merchant !== null
            && $this->user() !== null
            && $this->user()->can('update', $merchant);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $merchant = app(CurrentMerchant::class)->get();

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('merchants', 'slug')->ignore($merchant?->getKey()),
            ],
        ];
    }

    /**
     * Normalize the incoming input before validation.
     *
     * Whitespace is trimmed from both fields. The slug is intentionally NOT
     * lowercased here: a lowercase, URL-safe format is strictly validated and
     * any non-conforming input is rejected with a validation error.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => Str::trim((string) $this->input('name')),
            'slug' => Str::trim((string) $this->input('slug')),
        ]);
    }
}
