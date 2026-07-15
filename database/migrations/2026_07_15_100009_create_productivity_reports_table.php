<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Derived, query-friendly productivity aggregates — one row per employee per
 * period (daily / weekly / monthly), produced by the rollup jobs from
 * activity_logs + application_usage. Dashboards and reports can read these
 * instead of re-aggregating raw data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productivity_reports', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            $table->enum('period_type', ['daily', 'weekly', 'monthly'])->default('daily');
            $table->date('period_date');

            $table->unsignedInteger('active_seconds')->default(0);
            $table->unsignedInteger('productive_seconds')->default(0);
            $table->unsignedInteger('unproductive_seconds')->default(0);
            $table->unsignedInteger('neutral_seconds')->default(0);
            $table->decimal('productivity_score', 5, 2)->default(0);

            $table->timestamps();

            $table->unique(['employee_id', 'period_type', 'period_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productivity_reports');
    }
};
