<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class CreateRefundRequest extends FormRequest
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
     * ISO-style code. Note: provider is deliberately NOT accepted from
     * request input — the refund provider is derived from the original
     * successful payment attempt server-side.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('currency')) {
            $this->merge([
                'currency' => strtoupper(trim((string) $this->input('currency'))),
            ]);
        }
    }

    /**
     * Validation rules.
     *
     * Note: amount uses integer:strict so numeric strings ("1000") and
     * floats (10.50) are rejected — only real JSON integers pass. Only
     * the fields defined here are ever forwarded to the domain layer, so
     * body fields like merchant_id, reference, status, or provider cannot
     * influence core refund data.
     *
     * @return array<string, array<int|string>>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer:strict', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'reason' => ['nullable', 'string', 'max:255'],
            'payment_attempt_id' => ['nullable', 'integer:strict', 'min:1'],
            'metadata' => ['nullable', 'array', 'max:10'],
        ];
    }

    /**
     * Provider-neutral data for the domain layer. Only whitelisted fields
     * are forwarded; omitted optional fields are removed so the domain
     * defaults (e.g. the payment's currency) apply.
     *
     * @return array<string, mixed>
     */
    public function refundData(): array
    {
        $data = [
            'amount' => $this->validated('amount'),
            'currency' => $this->validated('currency'),
            'reason' => $this->validated('reason'),
            'payment_attempt_id' => $this->validated('payment_attempt_id'),
        ];

        $metadata = $this->validated('metadata');

        if (is_array($metadata)) {
            $data['request_metadata'] = $metadata;
        }

        return array_filter($data, static fn ($value) => $value !== null);
    }
}
