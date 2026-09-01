<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Services\Agent\AgentEnrollmentCredentialService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAgentEnrollmentCredential extends Command
{
    protected $signature = 'treck:agent-enrollment-create
        {--organization= : Target organization id or slug}
        {--name=Agent enrollment : Human-readable credential name}
        {--expires= : Optional expiry datetime}
        {--max-uses=1 : Maximum successful registrations allowed}
        {--created-by= : Optional creator user id or email for audit metadata}';

    protected $description = 'Create a one-time organization-specific agent enrollment credential.';

    public function handle(AgentEnrollmentCredentialService $service): int
    {
        $organization = $this->organization();

        if (! $organization || $organization->isSuspended()) {
            $this->error('Target organization was not found or is suspended.');

            return self::FAILURE;
        }

        $maxUses = $this->option('max-uses') !== null ? (int) $this->option('max-uses') : 1;

        if ($maxUses < 1) {
            $this->error('Maximum uses must be at least 1.');

            return self::FAILURE;
        }

        $created = $service->create(
            organization: $organization,
            name: (string) $this->option('name'),
            expiresAt: $this->option('expires') ? now()->parse((string) $this->option('expires')) : null,
            maxUses: $maxUses,
            actor: $this->actor(),
        );

        $credential = $created['credential'];

        $this->info('Enrollment credential created. The plaintext secret is shown once.');
        $this->line('organization_id='.$organization->id);
        $this->line('credential_id='.$credential->id);
        $this->line('public_id='.$credential->public_id);
        $this->line('secret='.$created['secret']);
        $this->line('platform_super_admin_assignments=0');

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

    private function actor(): ?User
    {
        $identifier = trim((string) $this->option('created-by'));

        if ($identifier === '') {
            return null;
        }

        if (filter_var($identifier, FILTER_VALIDATE_INT) !== false) {
            return User::find((int) $identifier);
        }

        return User::where('email', $identifier)->first();
    }
}
