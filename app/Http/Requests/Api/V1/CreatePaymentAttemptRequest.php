<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\PaymentProviderName;
use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentAttemptRequest extends FormRequest
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
     * Normalize the provider name before validation so resolution is
     * case-insensitive (Stripe, STRIPE, stripe all resolve to "stripe").
     * The provider list is NOT duplicated here — validity is checked
     * against PaymentProviderName.
     */
    protected function prepareForValidation(): void
    {
        // Only normalize genuine strings; anything else (arrays, numbers,
        // etc.) is left untouched so the "string" validation rule rejects
        // it with a standard 422 validation error.
        if (is_string($provider = $this->input('provider'))) {
            $this->merge([
                'provider' => PaymentProviderName::normalize($provider),
            ]);
        }
    }

    /**
     * Validation rules.
     *
     * @return array<string, array<int|string>>
     */
    public function rules(): array
    {
        return [
            'provider' => [
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    // Non-string values are already rejected by the "string"
                    // rule; only validate genuine strings here.
                    if (is_string($value) && ! PaymentProviderName::isValid($value)) {
                        $fail('The selected provider is invalid. Supported providers: '
                            .implode(', ', array_column(PaymentProviderName::cases(), 'value')).'.');
                    }
                },
            ],
        ];
    }

    /**
     * The normalized, validated provider name or null to let the
     * resolver pick the default provider.
     */
    public function requestedProvider(): ?string
    {
        return $this->validated('provider');
    }
}
