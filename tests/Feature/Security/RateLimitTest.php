<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /** SEC-2: the login endpoint is throttled (5/min per email+IP). */
    public function test_login_endpoint_is_rate_limited(): void
    {
        $payload = [
            'email' => 'nobody@treck.test',
            'password' => 'wrong-password',
            'device_name' => 'test-device',
        ];

        // First 5 attempts hit the controller → 422 (invalid credentials).
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', $payload)->assertStatus(422);
        }

        // The 6th is blocked by the rate limiter.
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429);
    }
}
