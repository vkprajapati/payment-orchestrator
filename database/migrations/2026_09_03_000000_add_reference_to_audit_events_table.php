<?php

use App\Models\AuditEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            // Public, URL-safe, externally exposable per-event identifier:
            // "evt_" + ULID, mirroring the payments (pay_) / refunds (ref_)
            // convention. ULIDs are URL-safe, lexicographically sortable,
            // never derived from the database id, and globally unique. The
            // UNIQUE constraint is the collision backstop.
            $table->string('reference')->nullable();
        });

        // Backfill any pre-existing rows (the original table shipped without
        // a public identifier) so the column can be enforced NOT NULL
        // unconditionally going forward.
        foreach (AuditEvent::query()->whereNull('reference')->cursor() as $event) {
            $event->forceFill(['reference' => 'evt_'.Str::ulid()])->save();
        }

        Schema::table('audit_events', function (Blueprint $table) {
            // Enforce the identifier going forward.
            $table->string('reference')->nullable(false)->change();
            $table->unique('reference', 'audit_events_reference_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_events', function (Blueprint $table) {
            $table->dropUnique('audit_events_reference_unique');
            $table->dropColumn('reference');
        });
    }
};
