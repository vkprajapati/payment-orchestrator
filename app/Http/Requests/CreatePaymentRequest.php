<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Web form request for creating a payment through the merchant dashboard.
 *
 * Mirrors the API contract (App\Http\Requests\Api\V1\CreatePaymentRequest)
 * but accepts HTML form input: the amount arrives as a numeric string from
 * the form field, so a coercive integer rule is used instead of the API's
 * strict JSON-integer rule. The backend domain action
 * (CreatePayment) remains authoritative for all business rules.
 *
 * merchant_id is deliberately never validated here: the owning merchant
 * is resolved server-side from the CurrentMerchant context.
 */
class CreatePaymentRequest extends FormRequest
{
    /**
     * Authorization: the merchant middleware guarantees a current
     * merchant; any managing member may create payments for it.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Normalize input before validation.
     *
     * The currency is uppercased so the domain layer always receives an
     * ISO-style code.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency' => strtoupper(trim((string) $this->input('currency'))),
            'description' => $this->exists('description') ? trim((string) $this->input('description')) : null,
        ]);
    }

    /**
     * Validation rules.
     *
     * Only these fields are forwarded to the domain layer, so form fields
     * like merchant_id, reference, or status can never influence core
     * payment data.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * The validated amount in the smallest currency unit.
     */
    public function paymentAmount(): int
    {
        return (int) $this->validated('amount');
    }

    /**
     * The validated ISO 4217 currency code.
     */
    public function currency(): string
    {
        return (string) $this->validated('currency');
    }

    /**
     * The validated optional description.
     */
    public function description(): ?string
    {
        $description = $this->validated('description');

        return is_string($description) && $description !== '' ? $description : null;
    }
}
