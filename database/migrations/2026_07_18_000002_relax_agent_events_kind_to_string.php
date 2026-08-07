<?php

use App\Enums\AgentEventKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — Application Usage Tracking.
 *
 * The `agent_events.kind` column was created as an enum of ('heartbeat',
 * 'session'), which on MySQL/SQLite becomes a hard ENUM / CHECK constraint.
 * Phase 7 introduces a third kind (`app_usage`), and future agent event kinds
 * would each require another schema migration.
 *
 * The set of valid kinds is already the single source of truth in
 * {@see AgentEventKind} and is enforced on every request by
 * StoreAgentEventRequest (kind ∈ AgentEventKind::values()). Relaxing the column
 * to a short string removes the redundant, hard-to-extend database constraint
 * without weakening validation: the application still rejects unknown kinds with
 * a 422 before anything is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_events', function (Blueprint $table) {
            $table->string('kind', 32)->change();
        });
    }

    public function down(): void
    {
        // Restore the original enum. Any rows with kinds outside the original
        // set would have to be removed first on a real rollback.
        Schema::table('agent_events', function (Blueprint $table) {
            $table->enum('kind', ['heartbeat', 'session'])->change();
        });
    }
};
