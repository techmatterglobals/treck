<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Screenshots are an opt-in feature (disabled by default; see config/treck.php).
 * Each row references the storage path of a capture, tied to the employee, the
 * computer, and optionally the session. Files live in object storage; only the
 * path/metadata is kept in MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('computer_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('activity_log_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->timestamp('captured_at');

            $table->timestamps();

            $table->index(['employee_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenshots');
    }
};
