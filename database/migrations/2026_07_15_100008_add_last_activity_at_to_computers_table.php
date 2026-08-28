<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `last_activity_at` to computers: the timestamp of the last *active*
 * sample (real keyboard/mouse input), distinct from `last_seen_at` which is the
 * last time the agent reported anything (including idle heartbeats).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->timestamp('last_activity_at')->nullable()->after('last_seen_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('computers', function (Blueprint $table) {
            $table->dropIndex(['last_activity_at']);
            $table->dropColumn('last_activity_at');
        });
    }
};
