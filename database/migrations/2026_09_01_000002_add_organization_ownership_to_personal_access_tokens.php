<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('personal_access_tokens', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id');
            }

            $table->index('organization_id', 'personal_tokens_organization_idx');
            $table->index(
                ['organization_id', 'tokenable_type', 'tokenable_id'],
                'personal_tokens_org_tokenable_idx',
            );
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreign('organization_id', 'personal_tokens_organization_fk')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Forward-only by design: token tenant ownership is security state and
        // must remain available during compatibility rollback.
    }
};
