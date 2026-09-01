<?php

namespace App\Models;

use Database\Factories\MerchantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'status', 'metadata'])]
class Merchant extends Model
{
    /** @use HasFactory<MerchantFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * The users that belong to the merchant.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * The API keys that belong to the merchant.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * The payments that belong to the merchant.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * The idempotency reservations that belong to the merchant.
     *
     * Reservations are always created through this relation so merchant
     * ownership is set server-side from the authenticated API key — the
     * column is intentionally not fillable on the model.
     */
    public function idempotencyKeys(): HasMany
    {
        return $this->hasMany(IdempotencyKey::class);
    }

    /**
     * The append-only audit trail that belongs to the merchant.
     *
     * Records are created only through this relation by the AuditLogger
     * service, so ownership is always the authenticated merchant. There is
     * intentionally no normal update or delete flow for audit records.
     */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /**
     * The refunds that belong to the merchant.
     *
     * Refunds are always created through a payment of this merchant (the
     * merchant_id column is set server-side from the payment's owner), so
     * this inverse relation can never cross the tenant boundary — useful
     * for merchant-scoped aggregates such as the dashboard summary.
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
