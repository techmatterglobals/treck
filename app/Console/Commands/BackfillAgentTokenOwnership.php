<?php

namespace App\Console\Commands;

use App\Models\Computer;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillAgentTokenOwnership extends Command
{
    protected $signature = 'treck:backfill-agent-token-ownership
        {--organization= : Target organization id or slug}
        {--dry-run : Report planned changes without writing them}
        {--verify : Read-only verification of remaining backfillable rows and ownership conflicts}';

    protected $description = 'Backfill organization ownership for existing Sanctum computer tokens.';

    public function handle(): int
    {
        $organization = $this->resolveOrganization();

        if (! $organization) {
            $this->error('Target organization was not found.');

            return self::FAILURE;
        }

        if ($organization->isSuspended()) {
            $this->error('Target organization is suspended.');

            return self::FAILURE;
        }

        $summary = $this->summarize($organization->id);

        $this->line('organization_id='.$organization->id);
        $this->line('agent_tokens_to_assign='.$summary['planned']);
        $this->line('agent_tokens_conflicts='.$summary['conflicted']);
        $this->line('agent_tokens_unresolved='.$summary['unresolved']);
        $this->line('agent_tokens_other_organizations='.$summary['other']);
        $this->line('platform_super_admin_assignments=0');

        if ((bool) $this->option('verify')) {
            return $summary['planned'] === 0 && $summary['conflicted'] === 0
                ? self::SUCCESS
                : self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->line('Dry run only; no data was changed.');

            return self::SUCCESS;
        }

        $updated = 0;

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new Computer)->getMorphClass())
            ->whereNull('organization_id')
            ->orderBy('id')
            ->chunkById(500, function ($tokens) use ($organization, &$updated) {
                foreach ($tokens as $token) {
                    $computerOrganizationId = Computer::query()
                        ->whereKey($token->tokenable_id)
                        ->value('organization_id');

                    if ((int) $computerOrganizationId !== (int) $organization->id) {
                        continue;
                    }

                    $updated += DB::table('personal_access_tokens')
                        ->where('id', $token->id)
                        ->whereNull('organization_id')
                        ->update(['organization_id' => $organization->id]);
                }
            });

        $this->info('agent tokens assigned: '.$updated.'.');
        $this->info('No platform-super-admin role was assigned.');

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $identifier = trim((string) $this->option('organization'));

        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_INT) !== false) {
            return Organization::find((int) $identifier);
        }

        return Organization::query()
            ->where('slug', Str::slug($identifier))
            ->first();
    }

    /**
     * @return array{planned:int,conflicted:int,unresolved:int,other:int}
     */
    private function summarize(int $organizationId): array
    {
        $counts = [
            'planned' => 0,
            'conflicted' => 0,
            'unresolved' => 0,
            'other' => 0,
        ];

        DB::table('personal_access_tokens')
            ->where('tokenable_type', (new Computer)->getMorphClass())
            ->orderBy('id')
            ->chunkById(500, function ($tokens) use (&$counts, $organizationId) {
                foreach ($tokens as $token) {
                    $computerOrganizationId = Computer::query()
                        ->whereKey($token->tokenable_id)
                        ->value('organization_id');

                    if ($token->organization_id !== null) {
                        if ($computerOrganizationId !== null && (int) $token->organization_id !== (int) $computerOrganizationId) {
                            $counts['conflicted']++;
                        }

                        continue;
                    }

                    if ($computerOrganizationId === null) {
                        $counts['unresolved']++;
                    } elseif ((int) $computerOrganizationId === $organizationId) {
                        $counts['planned']++;
                    } else {
                        $counts['other']++;
                    }
                }
            });

        return $counts;
    }
}
