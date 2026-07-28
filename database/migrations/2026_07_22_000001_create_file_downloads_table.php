<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 12 — File Download Monitoring. One row per file the agent observed being
 * saved into a user download location. **Metadata only** — file contents are
 * never read, uploaded or stored.
 *
 * Fed by the existing agent-event pipeline (a new `file_download` event kind
 * projected here), so employee attribution reuses the Phase 11 Windows-username
 * resolution. `employee_id` is nullable so a download from an as-yet-unmapped
 * Windows account is still recorded. Idempotent per (computer_id, event_key)
 * where event_key is the agent's per-event idempotency key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_downloads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('computer_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('employee_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('windows_username', 191)->nullable();

            // Source application context.
            $table->string('application_name')->nullable();
            $table->string('process_name')->nullable();
            $table->string('window_title')->nullable();

            // File metadata (never contents).
            $table->string('file_name');
            $table->string('file_extension', 32)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('local_path', 1024)->nullable();
            $table->string('download_folder', 1024)->nullable();
            $table->string('sha256_hash', 64)->nullable();

            $table->timestamp('downloaded_at');
            $table->string('session_id', 64)->nullable();

            // The agent's per-event idempotency key — makes projection idempotent.
            $table->string('event_key', 191);

            $table->timestamps();

            // Idempotent uploads: the same queued event projects exactly once.
            $table->unique(['computer_id', 'event_key']);

            // Scoped/report query patterns (indexed lookups; no historical scans).
            $table->index(['employee_id', 'downloaded_at']);
            $table->index(['computer_id', 'downloaded_at']);
            $table->index(['file_extension', 'downloaded_at']);
            $table->index('downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_downloads');
    }
};
