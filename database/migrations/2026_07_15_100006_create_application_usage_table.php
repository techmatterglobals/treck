<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Application usage records how long a foreground application (or browser
 * domain) was used, tied to the employee, the computer, and — when known — the
 * activity_logs session it happened in. The productivity classification feeds
 * the productivity score. Deleting the parent session cascades its usage rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_usage', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('computer_id')
                ->constrained()
                ->cascadeOnDelete();

            // The session this usage belongs to (nullable if not correlated).
            $table->foreignId('activity_log_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('application_name');
            $table->string('executable')->nullable();
            $table->string('window_title')->nullable();
            $table->string('category', 120)->nullable();

            $table->enum('productivity', ['productive', 'unproductive', 'neutral'])
                ->default('neutral');

            $table->timestamp('used_at');
            $table->unsignedInteger('duration_seconds')->default(0);

            $table->timestamps();

            $table->index(['employee_id', 'used_at']);
            $table->index(['computer_id', 'used_at']);
            $table->index('application_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_usage');
    }
};
