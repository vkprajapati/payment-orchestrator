<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreatePaymentRequest extends FormRequest
{
    /**
     * Authorization happened in the api.key middleware: the request is
     * already bound to an authenticated merchant via ApiRequestContext.
     * There is no session user and no policy to check here.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize input before validation.
     *
     * The currency is uppercased so the domain layer always receives an
     * ISO-style code. The idempotency key is taken EXCLUSIVELY from the
     * Idempotency-Key HTTP header — a key in the JSON body is ignored —
     * and is trimmed but never hashed (it is not a secret).
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'currency' => strtoupper(trim((string) $this->input('currency'))),
        ]);

        if ($this->headers->has('Idempotency-Key')) {
            $this->merge([
                'idempotency_key' => trim((string) $this->header('Idempotency-Key')),
            ]);
        }
    }

    /**
     * Validation rules.
     *
     * Note: amount uses integer:strict so numeric strings ("1050") and
     * floats (10.50) are rejected — only real JSON integers pass. Only
     * the fields defined here are ever forwarded to the domain layer,
     * so body fields like merchant_id, reference, or status cannot
     * influence core payment data.
     *
     * @return array<string, array<int|string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer:strict', 'min:1'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
            'idempotency_key' => ['sometimes', 'required', 'string', 'max:255'],
        ];
    }

    /**
     * The validated idempotency key from the Idempotency-Key header,
     * or null when the header was not provided.
     */
    public function idempotencyKey(): ?string
    {
        return $this->validated('idempotency_key');
    }
}
