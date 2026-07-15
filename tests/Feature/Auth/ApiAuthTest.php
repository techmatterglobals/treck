<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_access_is_blocked(): void
    {
        $this->getJson('/api/v1/activity/live')->assertUnauthorized();
    }

    public function test_invalid_bearer_token_is_rejected(): void
    {
        $this->withToken('bogus-token')->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    /** Authenticated but without the required permission → 403. */
    public function test_user_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('spa', ['*'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/activity/live')->assertForbidden();
    }
}
