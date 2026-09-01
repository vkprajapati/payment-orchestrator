<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class RotateApiKeyRequest extends FormRequest
{
    /**
     * Tenant resolution comes from the authenticated API key, never from
     * input — any merchant_id in the payload is ignored.
     *
     * Deliberately no body fields: the replacement key inherits the old
     * key's name and label, keeping rotation a one-shot security action
     * rather than a second creation form. A fresh expiration can be set
     * afterwards by rotating again or creating a new key.
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
        return [];
    }
}
