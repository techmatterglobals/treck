<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shared-PC live presence attribution.
 *
 * The original computer_presence migration deliberately did NOT store an
 * employee, reasoning that the owner is a stable property of the computer
 * (computers.employee_id). That holds for single-user machines but breaks on a
 * SHARED PC: computers.employee_id is only the static/default owner, while the
 * person actually at the keyboard is whoever the newest accepted event was
 * attributed to (resolved Windows user -> computer_users -> employee).
 *
 * This adds a materialized runtime owner:
 *   current_employee_id - the employee from the newest accepted presence-driving
 *                         event (heartbeat/session). Nullable; nullOnDelete so
 *                         hard-deleting an employee simply clears the pointer
 *                         rather than deleting presence. Indexed for the
 *                         employee-level read model (status map / online count).
 *
 * computers.employee_id is left untouched (still the static/default owner and
 * the legacy fallback used when current_employee_id is null).
 *
 * Backfill: existing rows are seeded from computers.employee_id so single-user
 * machines do not momentarily appear Offline right after the migration; the next
 * accepted heartbeat overwrites it with the correct runtime employee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('computer_presence', function (Blueprint $table) {
            $table->foreignId('current_employee_id')
                ->nullable()
                ->after('computer_id')
                ->constrained('employees')
                ->nullOnDelete();

            $table->index('current_employee_id');
        });

        // Seed legacy rows from the computer's static owner. Correlated subquery
        // against a different table -> valid on both MySQL and SQLite. New DBs
        // (tests) have no rows yet, so this is a harmless no-op there.
        DB::table('computer_presence')->update([
            'current_employee_id' => DB::raw(
                '(select employee_id from computers where computers.id = computer_presence.computer_id)'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('computer_presence', function (Blueprint $table) {
            $table->dropIndex(['current_employee_id']);
            $table->dropConstrainedForeignId('current_employee_id');
        });
    }
};
