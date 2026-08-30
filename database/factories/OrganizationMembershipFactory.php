<?php

namespace Database\Factories;

use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationMembershipFactory extends Factory
{
    protected $model = OrganizationMembership::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'status' => MembershipStatus::Active,
            'role' => 'employee',
            'is_owner' => false,
            'joined_at' => now(),
            'invited_by_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => MembershipStatus::Inactive]);
    }

    public function owner(): static
    {
        return $this->state(fn () => [
            'role' => 'organization-owner',
            'is_owner' => true,
        ]);
    }
}
