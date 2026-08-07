<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 11 — organization hierarchy. Adds the direct Manager link and a status
 * flag to employees. Both are additive and nullable/defaulted, so existing rows
 * and single-user deployments are unaffected:
 *
 *   - manager_user_id : the supervising Manager (a users row). Null = unassigned.
 *     nullOnDelete so removing a manager account simply unassigns their team
 *     rather than deleting employees. Indexed for manager-scoped lookups.
 *   - status          : lifecycle flag ('active' by default) surfaced in the
 *     admin UI; does not replace soft-deletes (which still preserve history).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('manager_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('status', 20)->default('active')->after('designation');

            $table->index('manager_user_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['manager_user_id']);
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('manager_user_id');
            $table->dropColumn('status');
        });
    }
};
