<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            // The refunded payment. Deleting the payment removes its refunds
            // (a refund is meaningless without its payment).
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            // Optionally the original successful attempt the money is coming
            // back through. Attempts may be pruned independently of refunds,
            // so deleting one only detaches the association (SET NULL).
            $table->foreignId('payment_attempt_id')->nullable()->constrained()->nullOnDelete();

            // Denormalized tenant key: payments already carry merchant_id,
            // but keeping it here makes tenant-scoped operational queries
            // index-friendly. Application code MUST keep it equal to
            // payment.merchant_id — it is not fillable and is set only by
            // the CreateRefund action from the payment itself.
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // Public refund identifier, e.g. ref_01JABCDE123456789XYZ:
            // "ref_" + ULID — URL-safe, lexicographically sortable, not
            // derived from the database ID. The UNIQUE constraint is the
            // collision backstop.
            $table->string('reference')->unique();

            // Stable provider identifier matching PaymentProviderName values.
            // Nullable: the provider is assigned when refund execution starts.
            $table->string('provider')->nullable();

            // Provider-side refund identifier. Deliberately NOT globally
            // unique: different providers may generate overlapping
            // identifiers. The (provider, provider_refund_id) index supports
            // future refund webhook reconciliation.
            $table->string('provider_refund_id')->nullable();

            $table->string('status')->default('pending');

            // Snapshot of the refunded amount in the smallest currency unit
            // (e.g. 1050 = $10.50). No floats.
            $table->unsignedBigInteger('amount')->comment('Smallest currency unit, e.g. 1050 = $10.50');

            // ISO 4217 code. Must always match the payment currency —
            // cross-currency refunds are not supported.
            $table->char('currency', 3)->comment('ISO 4217 code, must match the payment currency');

            $table->string('reason')->nullable();
            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();

            // Provider-neutral context: what was requested and what the
            // provider answered. Never store provider credentials here.
            $table->jsonb('request_metadata')->nullable();
            $table->jsonb('response_metadata')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Refund listing per payment / per merchant, newest first.
            $table->index(['payment_id', 'created_at']);
            $table->index(['merchant_id', 'created_at']);

            // Operational filters for dashboards and future reconciliation.
            $table->index('provider');
            $table->index('status');

            // Future refund webhook lookups search by provider + provider
            // refund id. An index, never a unique constraint.
            $table->index(['provider', 'provider_refund_id']);
        });

        // Enforce positive amounts at the database level, independent of
        // application validation.
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_amount_positive');

        Schema::dropIfExists('refunds');
    }
};
