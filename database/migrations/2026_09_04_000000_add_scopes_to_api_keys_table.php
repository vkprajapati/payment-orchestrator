<?php

declare(strict_types=1);

use App\Enums\ApiKeyScope;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add per-key authorization scopes.
     *
     * Backwards compatibility: the column is nullable, and EXISTING rows
     * are backfilled with the full set of currently supported scopes so
     * pre-scope integrations keep their unrestricted access. The model
     * additionally treats a NULL scopes value as full access (defense in
     * depth for rows created outside the application layer).
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->json('scopes')->nullable()->after('metadata');
        });

        DB::table('api_keys')
            ->whereNull('scopes')
            ->update(['scopes' => json_encode(ApiKeyScope::values())]);
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropColumn('scopes');
        });
    }
};
