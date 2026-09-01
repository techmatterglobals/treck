<?php

namespace Database\Factories;

use App\Models\AgentEnrollmentCredential;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AgentEnrollmentCredentialFactory extends Factory
{
    protected $model = AgentEnrollmentCredential::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Agent enrollment',
            'public_id' => Str::lower(fake()->unique()->bothify('????????????????')),
            'secret_hash' => Hash::make('secret'),
            'expires_at' => now()->addDay(),
            'max_uses' => 1,
            'uses_count' => 0,
            'last_used_at' => null,
            'revoked_at' => null,
            'created_by' => null,
            'revoked_by' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function exhausted(): static
    {
        return $this->state(fn () => ['max_uses' => 1, 'uses_count' => 1]);
    }
}
