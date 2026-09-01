<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\ApiKey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe public representation of an API key.
 *
 * Deliberate exclusions (never exposed): id, merchant_id, key_hash,
 * key_prefix, metadata, raw secret material. Only public identifiers
 * and safe lifecycle state surface through this resource.
 *
 * @mixin ApiKey
 */
class ApiKeyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reference' => $this->reference,
            'name' => $this->name,
            'label' => $this->label,
            'status' => $this->revoked_at !== null
                ? 'revoked'
                : ($this->isExpired() ? 'expired' : 'active'),
            'last_used_at' => $this->last_used_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'revoked_at' => $this->revoked_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
