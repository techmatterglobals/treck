<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An employee is the HR profile paired one-to-one with a user (login) account.
 * Deleting the user cascades to the employee. The department link is nullable
 * and set to null if the department is removed. Soft deletes preserve history
 * (attendance, activity) when an employee leaves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // 1:1 with users; unique enforces one employee per user account.
            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            // Owning department (nullable).
            $table->foreignId('department_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('employee_code', 40)->unique();
            $table->string('designation', 120)->nullable();
            $table->string('phone', 30)->nullable();
            $table->date('joined_on')->nullable();

            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
