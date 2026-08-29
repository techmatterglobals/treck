<?php

namespace Database\Factories;

use App\Models\AgentHealthReport;
use App\Models\Computer;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentHealthReportFactory extends Factory
{
    protected $model = AgentHealthReport::class;

    public function definition(): array
    {
        return [
            'computer_id' => Computer::factory(),
            'agent_version' => '1.0.0',
            'config_revision' => 'default',
            'pending_event_count' => 0,
            'helper_running' => true,
            'helper_session_id' => 1,
            'service_started_at' => now()->subHours(2),
            'last_capture_at' => now()->subMinute(),
            'last_successful_sync_at' => now()->subMinute(),
            'last_error_category' => null,
            'reported_at' => now(),
            'received_at' => now(),
        ];
    }
}
