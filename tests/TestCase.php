<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\PermissionRegistrar;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        if ($this->app?->bound(PermissionRegistrar::class)) {
            $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }

        parent::tearDown();
    }
}
