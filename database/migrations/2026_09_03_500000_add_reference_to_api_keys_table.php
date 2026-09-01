<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            // Public identifier for the API-key lifecycle endpoints,
            // consistent with the evt_/pay_/ref_ reference strategy.
            // Nullable on add so existing keys survive the migration;
            // the app backfills it lazily when needed.
            $table->string('reference')->nullable()->after('id');
            $table->string('label')->nullable()->after('name');

            $table->unique('reference');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropUnique(['reference']);
            $table->dropColumn(['reference', 'label']);
        });
    }
};
