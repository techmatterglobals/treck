<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time agent enrollment codes (installer flow). A code is presented once to
 * an admin in plaintext and stored here only as a SHA-256 hash — the plaintext
 * is never persisted. Enrolling a computer consumes a use; codes are single-use
 * by default, may expire, and can be revoked. This replaces distributing the
 * global provisioning key to employee machines, without removing it (the legacy
 * /api/agent/register flow stays intact for existing installs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_enrollment_codes', function (Blueprint $table) {
            $table->id();

            // SHA-256 hex of the normalized code — unique, never the plaintext.
            $table->char('code_hash', 64)->unique();
            // Last group of the code (e.g. "7Q2M") so an admin can identify a row
            // in a list without the plaintext ever being stored or shown again.
            $table->string('code_last_four', 8)->nullable();
            $table->string('label')->nullable();

            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('uses')->default(0);

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Audit: who created it, and the last consumption.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->foreignId('last_computer_id')->nullable()->constrained('computers')->nullOnDelete();

            $table->timestamps();

            $table->index(['revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_enrollment_codes');
    }
};
