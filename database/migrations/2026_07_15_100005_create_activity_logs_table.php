<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An activity log is one PC session: a login → logout span on a specific
 * computer for a specific employee, with the accumulated active/idle seconds
 * and the session's current/last status. This is the source of truth for
 * "PC login/logout time", "active time", "idle time" and "computer status".
 *
 * `logout_at` is null while the session is open. `end_reason` records how it
 * closed (clean logout, shutdown, idle timeout, or reconciled by the server
 * after an agent crash). `work_date` is denormalized for fast daily grouping.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('computer_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->timestamp('login_at');
            $table->timestamp('logout_at')->nullable();

            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('idle_seconds')->default(0);

            $table->enum('status', ['online', 'idle', 'locked', 'offline'])
                ->default('offline');

            $table->enum('end_reason', ['logout', 'shutdown', 'timeout', 'reconciled'])
                ->nullable();

            $table->date('work_date')->index();

            $table->timestamps();

            // Fast lookups for "sessions of employee X" / "sessions on PC Y".
            $table->index(['employee_id', 'login_at']);
            $table->index(['computer_id', 'login_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
