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
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();

            // Owning merchant — ALWAYS from the authenticated API key, never
            // from request input. Deleting the merchant removes its keys.
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // The opaque client-supplied Idempotency-Key header value
            // (trimmed, at most 255 characters). Not a secret; never hashed.
            $table->string('key');

            // Request scope. The same key may be reused across different
            // endpoints/methods without colliding — only the identical
            // (merchant, key, method, path) tuple is deduplicated.
            $table->string('request_method', 10);
            $table->string('request_path');

            // SHA-256 fingerprint of method + normalized path + canonically
            // encoded validated body. Detects key reuse with a different
            // payload (controlled 409 instead of a wrong replay).
            $table->string('request_hash', 64);

            // processing → completed. A processing record means the domain
            // operation is in flight: duplicates receive a controlled 409.
            // Failed operations RELEASE the reservation (record deleted) so
            // a corrected retry is never blocked forever.
            $table->string('status')->default('processing');

            // The exact final API response to replay: status code + raw JSON
            // body. Null while the operation is still in flight.
            $table->unsignedInteger('response_status')->nullable();
            $table->json('response_body')->nullable();

            $table->timestamp('locked_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // The database is the source of truth against concurrent
            // duplicates: two simultaneous identical requests can never both
            // reserve the same scope — the second INSERT violates this
            // constraint and is converted into a controlled conflict.
            $table->unique(['merchant_id', 'key', 'request_method', 'request_path']);

            // Time-ordered per-merchant listing for cleanup/auditing.
            $table->index(['merchant_id', 'created_at']);

            // Operational scans for stuck/reservations by state.
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
