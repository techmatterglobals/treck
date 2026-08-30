<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_health_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('computer_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('agent_version', 20)->nullable();
            $table->string('config_revision', 64)->nullable();
            $table->unsignedInteger('pending_event_count')->default(0);
            $table->boolean('helper_running')->default(false);
            $table->unsignedInteger('helper_session_id')->nullable();
            $table->timestamp('service_started_at')->nullable();
            $table->timestamp('last_capture_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->string('last_error_category', 80)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamp('received_at')->index();
            $table->timestamps();

            $table->index(['agent_version', 'config_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_health_reports');
    }
};
