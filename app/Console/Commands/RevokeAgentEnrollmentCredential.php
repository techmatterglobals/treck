<?php

namespace App\Console\Commands;

use App\Models\AgentEnrollmentCredential;
use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\AgentEnrollmentCredentialService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RevokeAgentEnrollmentCredential extends Command
{
    protected $signature = 'treck:agent-enrollment-revoke
        {--organization= : Target organization id or slug}
        {--credential= : Credential id or public id}
        {--revoked-by= : Optional revoker user id or email for audit metadata}';

    protected $description = 'Revoke an organization agent enrollment credential.';

    public function handle(AgentEnrollmentCredentialService $service): int
    {
        $organization = $this->organization();
        $credential = $organization ? $this->credential($organization) : null;

        if (! $organization || ! $credential) {
            $this->error('Target organization or credential was not found.');

            return self::FAILURE;
        }

        $service->revoke($credential, $this->actor());

        $this->info('Enrollment credential revoked.');
        $this->line('organization_id='.$organization->id);
        $this->line('credential_id='.$credential->id);
        $this->line('public_id='.$credential->public_id);

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

    private function credential(Organization $organization): ?AgentEnrollmentCredential
    {
        $identifier = trim((string) $this->option('credential'));

        if ($identifier === '') {
            return null;
        }

        return AgentEnrollmentCredential::query()
            ->forOrganization($organization)
            ->where(function ($query) use ($identifier) {
                $query->where('public_id', $identifier);

                if (filter_var($identifier, FILTER_VALIDATE_INT) !== false) {
                    $query->orWhereKey((int) $identifier);
                }
            })
            ->first();
    }

    private function actor(): ?User
    {
        $identifier = trim((string) $this->option('revoked-by'));

        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_INT) !== false) {
            return User::find((int) $identifier);
        }

        return User::where('email', $identifier)->first();
    }
}
