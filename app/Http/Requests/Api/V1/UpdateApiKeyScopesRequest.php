<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\ApiKeyScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Update the scopes of an existing API key.
 *
 * Scopes are optional on key creation (defaults to full access) but
 * mandatory and must be non-empty on update — an API key with no
 * scopes would be permanently unusable.
 *
 * merchant_id is deliberately never validated here: ownership is
 * resolved structurally from the authenticated API key context.
 */
class UpdateApiKeyScopesRequest extends FormRequest
{
    /**
     * @return array<string, array<int|string>>
     */
    public function rules(): array
    {
        $values = ApiKeyScope::values();

        return [
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'distinct:strict', Rule::in($values)],
        ];
    }

    /**
     * Normalize the validated scopes into a clean, distinct list.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        $data = $this->validated('scopes');

        if (! is_array($data)) {
            return [];
        }

        return array_values(array_unique($data));
    }
}
