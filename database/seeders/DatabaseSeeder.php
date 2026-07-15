<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
        ]);

        // Demo data for local/staging only — never in production.
        if (! app()->environment('production')) {
            $this->call(DemoDataSeeder::class);
        }
    }
}
