<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9 — Notification history. One row per delivered (or attempted)
 * notification, per recipient + channel. `dedupe_key` powers throttling /
 * flood-prevention; indexes support the dashboard's unread, severity, type and
 * date-range queries at scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();

            $table->foreignId('recipient_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('computer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();

            $table->string('event_type', 64);
            $table->string('severity', 16);
            $table->string('title');
            $table->text('message');
            $table->string('channel', 32);           // in_app | email | …

            $table->string('dedupe_key', 191)->nullable();
            $table->string('status', 24)->default('pending'); // pending|delivered|failed|read
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['recipient_id', 'read_at']);
            $table->index(['severity', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['channel', 'status']);
            $table->index(['dedupe_key', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
