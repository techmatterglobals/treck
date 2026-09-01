<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_enrollment_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('public_id', 32)->unique();
            $table->string('secret_hash');
            $table->timestamp('expires_at')->nullable()->index();
            $table->unsignedInteger('max_uses')->nullable()->default(1);
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'revoked_at', 'expires_at'], 'agent_enroll_org_status_idx');
            $table->index(['organization_id', 'created_at'], 'agent_enroll_org_created_idx');
        });
    }

    public function down(): void
    {
        // Forward-only by design: enrollment credential history is
        // security-relevant and must not be dropped by behavioral rollback.
    }
};
