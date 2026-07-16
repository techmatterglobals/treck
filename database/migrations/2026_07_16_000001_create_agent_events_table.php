<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The durable landing table for the desktop agent's offline queue (M6).
 *
 * The agent batches heartbeat and session events into a local SQLite queue and
 * drains them to `POST /api/agent/events`. Each row here is one acknowledged
 * event: the server stores it inside a transaction and only then returns
 * success, which is the signal the agent uses to delete the event from its
 * local queue.
 *
 * `payload` is the opaque event body as produced by the agent (kept verbatim so
 * later milestones can project it into the domain tables without re-ingesting).
 * `occurred_at` is the agent-side capture time; `received_at` is when the server
 * persisted it. Idempotency is enforced per device via a unique
 * (computer_id, idempotency_key) index so a re-sent event is a safe no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('computer_id')
                ->constrained()
                ->cascadeOnDelete();

            // Denormalized from the computer at ingest time (SEC-1: never trusted
            // from the request body). Nullable so an unassigned device can still
            // queue liveness events without losing them.
            $table->foreignId('employee_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->enum('kind', ['heartbeat', 'session']);

            $table->string('idempotency_key');

            $table->json('payload');

            $table->timestamp('occurred_at');
            $table->timestamp('received_at');

            $table->timestamps();

            // Idempotency: a device may submit the same event any number of times;
            // it is stored exactly once.
            $table->unique(['computer_id', 'idempotency_key']);

            // Fast draining/inspection of a device's event stream by time/kind.
            $table->index(['computer_id', 'occurred_at']);
            $table->index(['kind', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_events');
    }
};
