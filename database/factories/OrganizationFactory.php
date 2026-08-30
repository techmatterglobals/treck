<?php

namespace Database\Factories;

use App\Enums\OrganizationStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 9999),
            'status' => OrganizationStatus::Active,
            'suspended_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => OrganizationStatus::Suspended,
            'suspended_at' => now(),
        ]);
    }
}
