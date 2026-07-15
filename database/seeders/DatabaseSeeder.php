<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            // Add module seeders here as they are built:
            // DepartmentSeeder::class,
            // EmployeeSeeder::class,
        ]);
    }
}
