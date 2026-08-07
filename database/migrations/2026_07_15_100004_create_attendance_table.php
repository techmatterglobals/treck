<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance is the derived daily summary per employee — one row per employee
 * per day (enforced by the composite unique index). It is computed from the
 * day's activity_logs (first login, last logout, total active/idle) and is what
 * the dashboard reads, so reports never scan the raw activity tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->date('work_date');
            $table->timestamp('first_in_at')->nullable();
            $table->timestamp('last_out_at')->nullable();

            $table->unsignedInteger('work_seconds')->default(0);
            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('idle_seconds')->default(0);

            $table->enum('status', ['present', 'late', 'absent', 'half_day', 'on_leave'])
                ->default('absent');

            // True when an admin/HR manually corrected the row (audited).
            $table->boolean('is_corrected')->default(false);

            $table->timestamps();

            // One attendance record per employee per day.
            $table->unique(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
    }
};
