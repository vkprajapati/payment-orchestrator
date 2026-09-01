<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            // Owning merchant — ALWAYS from the authenticated API key
            // (ApiRequestContext), never from request input. Deleting the
            // merchant removes its audit history.
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // Centralized event name (AuditEventName), e.g. payment.created,
            // payment.processing_requested, refund.created.
            $table->string('event');

            // The authenticated API request that produced the event. Path is
            // normalized (no query string, no trailing slash).
            $table->string('http_method', 10);
            $table->string('path');

            // Final public response: HTTP status plus the controlled outcome
            // category (AuditOutcome). No internal details, no stack traces.
            $table->unsignedInteger('response_status')->nullable();
            $table->string('outcome')->nullable();

            // Public resource references (pay_/ref_ ULIDs) — NEVER internal
            // IDs. Null when the resource could not be resolved safely (an
            // unknown/cross-merchant reference is not recorded to avoid
            // leaking existence).
            $table->string('payment_reference')->nullable();
            $table->string('refund_reference')->nullable();

            // Reserved capability flag: true when the response was served as
            // an idempotent replay. Domain events are logged once on first
            // execution only.
            $table->boolean('idempotency_replayed')->nullable();

            // Explicitly whitelisted, sanitized metadata (AuditLogger). Never
            // raw request bodies, headers, or provider responses.
            $table->jsonb('metadata')->nullable();

            // When the request was performed (server clock).
            $table->timestamp('performed_at');

            $table->timestamps();

            // Merchant audit retrieval, newest first.
            $table->index(['merchant_id', 'created_at']);

            // Per-merchant filtering by event type (investigations/dashboards).
            $table->index(['merchant_id', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
