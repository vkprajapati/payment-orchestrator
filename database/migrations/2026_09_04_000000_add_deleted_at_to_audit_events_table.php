<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add SoftDeletes support to audit_events so the archive/prune
     * lifecycle can soft-delete (archive) events while preserving them
     * for forensic access, then permanently prune archived rows later.
     *
     * Active rows: deleted_at IS NULL (normal read paths).
     * Archived rows: deleted_at set; excluded from normal queries by
     * Laravel SoftDeletes global scope, queryable via withTrashed()/onlyTrashed().
     *
     * Reversible; nullable so the column can be dropped without data loss
     * (archived rows are retained until explicit hard-prune).
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->softDeletes()->after('performed_at');
        });

        // Partial index for the active-row hot path. The archive action and
        // the health stale-count query filter on performed_at against ACTIVE
        // rows (deleted_at IS NULL) exclusively, so a partial index keeps
        // those lookups fast without indexing archived history. The syntax
        // is valid on both PostgreSQL (production) and SQLite (tests).
        DB::statement(
            'CREATE INDEX audit_events_active_performed_at_idx '
            .'ON audit_events (performed_at) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS audit_events_active_performed_at_idx');

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn('deleted_at');
        });
    }
};
