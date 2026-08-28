<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Materialized current-state table for the real-time presence engine (Phase 6).
 *
 * Exactly one row per computer holds its latest presence, projected forward as
 * each agent event is ingested. The dashboard and details page read only this
 * table, so live status never requires scanning the (potentially millions of)
 * rows in `agent_events` - those remain for audit/history only.
 *
 * Columns:
 *   status             - current PresenceStatus (materialized).
 *   last_heartbeat_at  - occurred_at of the most recent heartbeat event.
 *   last_activity_at   - occurred_at of the most recent "active" signal
 *                        (active heartbeat / Unlock / Logon).
 *   last_event_at      - occurred_at of the most recent processed event
 *                        (agent clock; used to reject stale/out-of-order events).
 *   last_synced_at     - received_at of the most recent event (server clock;
 *                        drives the "missing heartbeat -> Offline" sweep).
 *   idle_seconds       - observed idle seconds from the latest heartbeat.
 *   session_started_at - start of the current session (set on Logon, cleared on
 *                        Logoff), for the "current session duration" display.
 *
 * Deviations from the Phase 6 "suggested" schema, and why the current shape is
 * preferable:
 *   - is_online / is_idle / is_locked: NOT stored. They are pure functions of
 *     `status` (see PresenceStatus::isOnline()); storing them would denormalize
 *     derived state that can drift out of sync with `status`.
 *   - employee_id: NOT stored. It is a stable property of the computer and is
 *     read via the computer relation, so duplicating it here would risk drift on
 *     re-assignment.
 *   - last_seen_at (suggested): represented by `last_synced_at` (server receive
 *     time). `last_event_at` (agent clock) is additionally kept purely to reject
 *     out-of-order events - a correctness need the single suggested column lacked.
 *   - current_session_started_at (suggested): named `session_started_at` here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_presence', function (Blueprint $table) {
            $table->id();

            $table->foreignId('computer_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('status')->default('offline');

            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->unsignedInteger('idle_seconds')->default(0);

            $table->timestamp('session_started_at')->nullable();

            $table->timestamps();

            // The sweep filters on (status, last_synced_at); the board groups by
            // status. Index both access paths.
            $table->index(['status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_presence');
    }
};
