<?php

namespace App\Models;

use App\Enums\AuditEventName;
use App\Enums\AuditOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An append-only merchant API audit record.
 *
 * Created ONLY by the AuditLogger service (via the merchant relation) and
 * never updated or deleted by normal application flow. There is no update
 * path, no delete path, and no API endpoint exposing writes. The record
 * stores no API keys, no Authorization headers, no raw request bodies,
 * no provider secrets, and no internal identifiers — only the event name,
 * request scope, safe outcome, and public resource references.
 *
 * @mixin Builder
 */
#[Fillable(['reference', 'event', 'http_method', 'path', 'response_status', 'outcome', 'payment_reference', 'refund_reference', 'idempotency_replayed', 'metadata', 'performed_at'])]
class AuditEvent extends Model
{
    /**
     * Get the merchant that owns this audit record.
     */
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    /**
     * Apply the shared public filter set (event, outcome, performed_at
     * window) used by both the list and export endpoints. Values are
     * validated against the enum whitelists before reaching this scope,
     * and the merchant scope is always supplied by the caller's query —
     * nothing here can weaken tenant isolation.
     */
    public function scopeFiltered(
        Builder $query,
        ?string $event,
        ?string $outcome,
        ?string $from,
        ?string $to,
    ): Builder {
        return $query
            ->when($event !== null, fn (Builder $q): Builder => $q->where('event', $event))
            ->when($outcome !== null, fn (Builder $q): Builder => $q->where('outcome', $outcome))
            ->when($from !== null, fn (Builder $q): Builder => $q->where('performed_at', '>=', $from))
            ->when($to !== null, fn (Builder $q): Builder => $q->where('performed_at', '<=', $to));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEventName::class,
            'outcome' => AuditOutcome::class,
            'metadata' => 'array',
            'idempotency_replayed' => 'boolean',
            'performed_at' => 'datetime',
        ];
    }
}
