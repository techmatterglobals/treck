<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A computer is a monitored workstation running the desktop agent. It is
 * identified by a stable hardware fingerprint (device_uuid) and assigned to an
 * employee. If the employee is removed, the computer is retained but unassigned
 * (nullOnDelete) so its historical activity stays intact.
 *
 * `status` mirrors the last-known live state (also held in Redis at runtime);
 * `last_seen_at` is refreshed on every heartbeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('device_uuid', 64)->unique();
            $table->string('hostname')->nullable();
            $table->string('os', 60)->nullable();
            $table->string('agent_version', 20)->nullable();

            $table->enum('status', ['online', 'idle', 'locked', 'offline'])
                ->default('offline')
                ->index();

            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('paired_at')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computers');
    }
};
