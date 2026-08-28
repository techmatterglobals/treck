<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — Per-administrator notification preferences. A missing row means
 * "defaults" (all enabled channels, info+ severity, no quiet hours), so
 * preferences are strictly opt-in overrides and backward compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            $table->json('channels');                       // enabled channels for this user
            $table->string('min_severity', 16)->default('info');
            $table->boolean('digest')->default(false);      // digest vs immediate
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
