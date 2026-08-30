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
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();

            // A payment attempt is always owned by exactly one payment.
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            // Denormalized tenant key: payments already carry merchant_id,
            // but keeping it here makes tenant-scoped operational queries
            // (and future per-merchant provider analytics) index-friendly.
            // Application code MUST keep it equal to payment.merchant_id —
            // it is not fillable and is set only by the CreatePaymentAttempt
            // action from the payment itself.
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // Stable provider identifier matching PaymentProviderName values
            // (mock, stripe, p24, razorpay, payu). Plain string, never a
            // native PostgreSQL ENUM, so providers can be added freely.
            $table->string('provider');

            // Provider-side transaction identifier, populated once the
            // provider returns one. Deliberately NOT globally unique:
            // different providers may generate overlapping identifiers.
            // A (provider, provider_payment_id) index supports the future
            // webhook flow (find the attempt for an incoming provider event)
            // without imposing cross-provider uniqueness.
            $table->string('provider_payment_id')->nullable();

            $table->string('status')->default('pending');

            // Snapshot of the payment amount/currency at attempt time,
            // in the smallest currency unit (e.g. 1050 = $10.50). No floats.
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);

            $table->string('failure_code')->nullable();
            $table->string('failure_message')->nullable();

            // Provider-neutral context: what was sent to the provider and
            // what it answered. Never store provider credentials here.
            $table->jsonb('request_metadata')->nullable();
            $table->jsonb('response_metadata')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Attempt listing per payment / per merchant, newest first.
            $table->index(['payment_id', 'created_at']);
            $table->index(['merchant_id', 'created_at']);

            // Operational filters for dashboards and the future router.
            $table->index('provider');
            $table->index('status');

            // Webhook lookups will search by provider + provider payment id.
            $table->index(['provider', 'provider_payment_id']);
        });

        // Enforce positive amounts at the database level, independent of
        // application validation.
        DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_amount_positive CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payment_attempts DROP CONSTRAINT IF EXISTS payment_attempts_amount_positive');

        Schema::dropIfExists('payment_attempts');
    }
};
