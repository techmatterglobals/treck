<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — shared-computer support. Maps each Windows account seen on a
 * computer to the employee who owns it, so one physical machine can be used by
 * several employees across shifts and every event is attributed to the correct
 * person from the logged-in Windows username.
 *
 *   one computer → many windows usernames → each mapped to one employee
 *
 * Backward compatible: computers with a single user (or legacy agents that do
 * not report a username) never create rows here; ingestion falls back to the
 * computer's assigned employee. `employee_id` is nullable so an as-yet-unmapped
 * ("pending") Windows account can be recorded and mapped later by the Super
 * Admin without losing events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('computer_users', function (Blueprint $table) {
            $table->id();

            $table->foreignId('computer_id')
                ->constrained()
                ->cascadeOnDelete();

            // Nullable: a pending (unresolved) Windows account has no employee yet.
            $table->foreignId('employee_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('windows_username', 191);

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('last_logout_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One mapping per (computer, windows account); the resolver upserts it.
            $table->unique(['computer_id', 'windows_username']);
            // Manager/employee-scoped lookups and pending-mapping sweeps.
            $table->index('employee_id');
            $table->index(['computer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('computer_users');
    }
};
