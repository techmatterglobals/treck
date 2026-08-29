<?php

namespace Tests\Feature\Agent;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression: agent API endpoints must always answer with JSON, even when the
 * client does NOT send `Accept: application/json`. Previously a failed
 * FormRequest on /api/agent/register redirected (302) to the site root, which
 * chained root -> dashboard -> /login, so the agent received the login HTML page
 * with a 200 instead of the real error. See bootstrap/app.php shouldRenderJsonWhen.
 */
class AgentApiJsonErrorsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string,mixed> */
    private function body(array $overrides = []): array
    {
        return array_merge([
            'enrollment_secret' => 'test-provisioning-key', // matches phpunit.xml
            'device_uuid' => '809c6089-a94a-4eb4-9226-5d53c80b2f54',
            'employee_code' => 'EMP-0001',
            'computer_name' => 'PC-100100105',
            'os' => 'Windows',
            'agent_version' => '1.0.0',
        ], $overrides);
    }

    public function test_register_validation_failure_returns_json_not_login_redirect(): void
    {
        // Plain POST (no Accept: application/json), unknown employee_code.
        $response = $this->post('/api/agent/register', $this->body());

        $response->assertStatus(422)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonValidationErrors('employee_code');
        $this->assertNull($response->headers->get('location'), 'must not redirect');
    }

    public function test_register_bad_enrollment_secret_returns_json_403_not_redirect(): void
    {
        Employee::factory()->create(['employee_code' => 'EMP-0001']);

        $response = $this->post('/api/agent/register', $this->body(['enrollment_secret' => 'wrong-secret']));

        $response->assertForbidden(); // 403
        $this->assertNull($response->headers->get('location'), 'must not redirect');
    }

    public function test_register_succeeds_with_valid_body_even_without_accept_header(): void
    {
        Employee::factory()->create(['employee_code' => 'EMP-0001']);

        $response = $this->post('/api/agent/register', $this->body());

        $response->assertCreated()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'Device registered.')
            ->assertJsonStructure(['data' => ['computer_id', 'employee_id', 'token', 'token_type']]);
    }

    public function test_protected_agent_route_returns_json_401_not_login_redirect(): void
    {
        // No token, plain request: must be a JSON 401, never an HTML login page.
        $response = $this->post('/api/agent/login', []);

        $response->assertUnauthorized(); // 401
        $this->assertNull($response->headers->get('location'), 'must not redirect to /login');
    }
}
