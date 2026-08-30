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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique()->comment('Public payment identifier, e.g. pay_01JABCDE123456789XYZ');
            $table->string('idempotency_key')->nullable();
            $table->unsignedBigInteger('amount')->comment('Smallest currency unit, e.g. 1050 = $10.50');
            $table->char('currency', 3)->comment('ISO 4217 code, e.g. USD');
            $table->string('status')->default('pending');
            $table->string('description')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            // Idempotency keys are unique per merchant, not globally. PostgreSQL
            // treats NULLs as distinct in unique indexes, so a merchant may hold
            // any number of payments without an idempotency key.
            $table->unique(['merchant_id', 'idempotency_key']);

            // Merchant listing pattern: WHERE merchant_id = ? ORDER BY created_at.
            $table->index(['merchant_id', 'created_at']);
            $table->index('status');
        });

        // Enforce positive amounts at the database level, independent of
        // application validation.
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_amount_positive');

        Schema::dropIfExists('payments');
    }
};
