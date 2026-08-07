<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 (#3) — event source metadata for screenshots.
 *
 * Records WHERE a capture was collected so administrators can confirm, in the
 * backend, that it came from the interactive helper (session 1+) rather than the
 * Session-0 service. Additive + nullable, so existing rows and the agent upload
 * remain backward compatible (older agents simply omit these fields).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screenshots', function (Blueprint $table) {
            $table->unsignedInteger('source_session_id')->nullable()->after('session_id');
            $table->string('source_user')->nullable()->after('source_session_id');
            $table->string('source_process')->nullable()->after('source_user');
            $table->string('collection_mode', 32)->nullable()->after('source_process');
        });
    }

    public function down(): void
    {
        Schema::table('screenshots', function (Blueprint $table) {
            $table->dropColumn(['source_session_id', 'source_user', 'source_process', 'collection_mode']);
        });
    }
};
