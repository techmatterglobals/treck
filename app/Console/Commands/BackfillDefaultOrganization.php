<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackfillDefaultOrganization extends Command
{
    protected $signature = 'treck:backfill-default-organization
        {--name=Treck Default Organization : Default organization name}
        {--slug=default : Default organization slug}
        {--dry-run : Report the changes without writing them}';

    protected $description = 'Create or locate the default organization and attach existing users idempotently.';

    public function handle(): int
    {
        $name = trim((string) $this->option('name'));
        $slug = Str::slug((string) $this->option('slug'));
        $dryRun = (bool) $this->option('dry-run');

        if ($name === '') {
            $this->error('The organization name must not be blank.');

            return self::FAILURE;
        }

        if ($slug === '') {
            $this->error('The organization slug must contain at least one letter or number.');

            return self::FAILURE;
        }

        $organization = Organization::where('slug', $slug)->first();
        $users = User::with('roles')->orderBy('id')->get();
        $organizationUsers = $users->reject(fn (User $user) => $user->hasRole('platform-super-admin'))->values();
        $existingMemberships = $organization
            ? OrganizationMembership::where('organization_id', $organization->id)->pluck('user_id')->all()
            : [];

        $existingLookup = array_fill_keys($existingMemberships, true);
        $wouldAttach = $organizationUsers->reject(fn (User $user) => isset($existingLookup[$user->id]));
        $ownerCount = $wouldAttach->filter(fn (User $user) => $user->hasRole(UserRole::Admin->value))->count();

        if ($dryRun) {
            $this->line('Dry run only; no data was changed.');
            $this->line('organization_slug='.$slug);
            $this->line('organization_exists='.($organization ? 'yes' : 'no'));
            $this->line('users_seen='.$users->count());
            $this->line('platform_users_skipped='.($users->count() - $organizationUsers->count()));
            $this->line('memberships_to_create='.$wouldAttach->count());
            $this->line('temporary_owners_to_create='.$ownerCount);

            return self::SUCCESS;
        }

        $createdOrganization = false;
        $createdMemberships = 0;
        $createdOwners = 0;

        DB::transaction(function () use ($name, $slug, $organizationUsers, &$organization, &$createdOrganization, &$createdMemberships, &$createdOwners) {
            $organization = Organization::firstOrCreate(
                ['slug' => $slug],
                ['name' => $name],
            );
            $createdOrganization = $organization->wasRecentlyCreated;

            foreach ($organizationUsers as $user) {
                $isOwner = $user->hasRole(UserRole::Admin->value);
                $role = $this->foundationRoleFor($user);

                $membership = OrganizationMembership::firstOrCreate(
                    [
                        'organization_id' => $organization->id,
                        'user_id' => $user->id,
                    ],
                    [
                        'status' => MembershipStatus::Active,
                        'role' => $role,
                        'is_owner' => $isOwner,
                        'joined_at' => now(),
                    ],
                );

                if ($membership->wasRecentlyCreated) {
                    $createdMemberships++;
                    $createdOwners += $isOwner ? 1 : 0;
                }
            }
        });

        $this->info(($createdOrganization ? 'Created' : 'Located').' organization '.$organization->slug.' (#'.$organization->id.').');
        $this->info("Users scanned: {$users->count()}.");
        $this->info('Platform users skipped: '.($users->count() - $organizationUsers->count()).'.');
        $this->info("Memberships created: {$createdMemberships}.");
        $this->info("Temporary owner memberships created: {$createdOwners}.");
        $this->info('No platform-super-admin role was created or granted.');

        return self::SUCCESS;
    }

    private function foundationRoleFor(User $user): string
    {
        if ($user->hasRole(UserRole::Admin->value)) {
            return 'organization-owner';
        }

        if ($user->hasRole(UserRole::Manager->value)) {
            return UserRole::Manager->value;
        }

        return UserRole::Employee->value;
    }
}
