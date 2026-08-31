<?php

namespace App\Models;

use App\Enums\IdempotencyStatus;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant-scoped idempotency reservation for a mutation API request.
 *
 * SECURITY: merchant_id is intentionally NOT fillable — ownership always
 * comes from the authenticated ApiRequestContext, never from request
 * input, so a client can never read or overwrite another merchant's
 * reservation. The record stores no API keys, no authorization headers,
 * and no secrets: only the opaque key, the request scope, the payload
 * fingerprint, and the exact public response to replay.
 *
 * @mixin Builder
 */
#[Fillable(['key', 'request_method', 'request_path', 'request_hash', 'status', 'response_status', 'response_body', 'locked_at', 'completed_at'])]
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_status' => 'integer',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the merchant that owns this reservation.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Determine whether the recorded response is replayable.
     */
    public function isCompleted(): bool
    {
        return $this->status->isCompleted();
    }

    /**
     * Determine whether the reserved operation is still in flight.
     */
    public function isProcessing(): bool
    {
        return $this->status === IdempotencyStatus::Processing;
    }
}
