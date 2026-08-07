<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 — Application Usage Tracking.
 *
 * Extends the existing `application_usage` table (additive; the Phase 2/5
 * productivity pipeline that reads used_at/productivity/duration_seconds is
 * untouched) so the desktop agent can upload completed usage *sessions*:
 *
 *   session_id  - the agent's per-session identity (GUID). Unique per computer,
 *                 giving idempotent projection on top of the agent_events dedup.
 *   ended_at    - session end (used_at remains the start). Duration is stored
 *                 too, but ended_at makes timeline/range queries direct.
 *
 * Existing rows keep session_id = null; MySQL/SQLite unique indexes allow
 * multiple NULLs, so the constraint only applies to agent-uploaded sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_usage', function (Blueprint $table) {
            $table->string('session_id', 64)->nullable()->after('window_title');
            $table->timestamp('ended_at')->nullable()->after('used_at');

            // Idempotency: one row per (computer, agent session id).
            $table->unique(['computer_id', 'session_id']);
            // Top-applications / time-per-application over a date range.
            $table->index(['application_name', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::table('application_usage', function (Blueprint $table) {
            $table->dropUnique(['computer_id', 'session_id']);
            $table->dropIndex(['application_name', 'used_at']);
            $table->dropColumn(['session_id', 'ended_at']);
        });
    }
};
