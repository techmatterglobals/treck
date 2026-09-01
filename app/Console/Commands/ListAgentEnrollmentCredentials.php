<?php

namespace App\Console\Commands;

use App\Models\AgentEnrollmentCredential;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ListAgentEnrollmentCredentials extends Command
{
    protected $signature = 'treck:agent-enrollment-list {--organization= : Target organization id or slug}';

    protected $description = 'List organization agent enrollment credentials without plaintext secrets or hashes.';

    public function handle(): int
    {
        $organization = $this->organization();

        if (! $organization) {
            $this->error('Target organization was not found.');

            return self::FAILURE;
        }

        $rows = AgentEnrollmentCredential::query()
            ->forOrganization($organization)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AgentEnrollmentCredential $credential) => [
                'id' => $credential->id,
                'public_id' => $credential->public_id,
                'name' => $credential->name,
                'status' => $credential->status(),
                'uses' => $credential->uses_count.'/'.($credential->max_uses ?? 'unlimited'),
                'expires_at' => $credential->expires_at?->toIso8601String() ?? '',
                'last_used_at' => $credential->last_used_at?->toIso8601String() ?? '',
            ]);

        $this->table(
            ['id', 'public_id', 'name', 'status', 'uses', 'expires_at', 'last_used_at'],
            $rows,
        );

        return self::SUCCESS;
    }

    private function organization(): ?Organization
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
}
