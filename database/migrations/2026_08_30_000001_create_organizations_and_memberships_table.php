<?php

use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 32)->default(OrganizationStatus::Active->value)->index();
            $table->timestamp('suspended_at')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'suspended_at']);
        });

        Schema::create('organization_user', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('status', 32)->default(MembershipStatus::Active->value);
            $table->string('role', 64)->default('employee');
            $table->boolean('is_owner')->default(false);
            $table->timestamp('joined_at')->nullable();

            $table->foreignId('invited_by_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_user');
        Schema::dropIfExists('organizations');
    }
};
