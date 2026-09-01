<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RevokeApiKeyRequest extends FormRequest
{
    /**
     * Tenant resolution comes from the authenticated API key, never
     * from input — any merchant_id in the payload is ignored.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Accept an optional reason in the body (validated but never
            // stored on the key itself — the audit log carries the context).
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
