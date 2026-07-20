<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — Screenshot Module.
 *
 * Extends the existing `screenshots` table (additive; the original opt-in
 * scaffolding kept only path/thumbnail_path/captured_at) so the desktop agent
 * can upload captures with full metadata and the dashboard can query them:
 *
 *   disk                - Laravel Storage disk the image lives on (configurable).
 *   filename            - display file name.
 *   image_hash          - SHA-256 of the compressed image; duplicate detection.
 *   monitor_number      - 0-based monitor index (multi-monitor captures).
 *   width / height      - captured resolution (DPI-correct).
 *   file_size           - compressed byte size.
 *   active_process / active_window_title - foreground context at capture time.
 *   session_id          - agent capture-session id (offline-queue correlation).
 *
 * The existing `path` column is reused as the storage path. Existing rows keep
 * image_hash = null; MySQL/SQLite unique indexes allow multiple NULLs, so the
 * (computer_id, image_hash) constraint only applies to agent-uploaded captures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screenshots', function (Blueprint $table) {
            $table->string('disk', 64)->default('local')->after('path');
            $table->string('filename')->nullable()->after('disk');
            $table->string('image_hash', 64)->nullable()->after('filename');
            $table->unsignedSmallInteger('monitor_number')->default(0)->after('image_hash');
            $table->unsignedInteger('width')->default(0)->after('monitor_number');
            $table->unsignedInteger('height')->default(0)->after('width');
            $table->unsignedBigInteger('file_size')->default(0)->after('height');
            $table->string('active_process')->nullable()->after('file_size');
            $table->string('active_window_title')->nullable()->after('active_process');
            $table->string('session_id', 64)->nullable()->after('active_window_title');

            // Idempotency + duplicate detection per device.
            $table->unique(['computer_id', 'image_hash']);

            // Dashboard / viewer query paths.
            $table->index(['computer_id', 'captured_at']);
            $table->index('captured_at');
        });
    }

    public function down(): void
    {
        Schema::table('screenshots', function (Blueprint $table) {
            $table->dropUnique(['computer_id', 'image_hash']);
            $table->dropIndex(['computer_id', 'captured_at']);
            $table->dropIndex(['captured_at']);
            $table->dropColumn([
                'disk', 'filename', 'image_hash', 'monitor_number', 'width',
                'height', 'file_size', 'active_process', 'active_window_title', 'session_id',
            ]);
        });
    }
};
