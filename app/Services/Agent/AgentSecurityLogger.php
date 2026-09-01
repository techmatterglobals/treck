<?php

namespace App\Services\Agent;

use App\Models\Computer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AgentSecurityLogger
{
    /**
     * @param  array<string,mixed>  $context
     */
    public function event(
        string $event,
        ?Organization $organization = null,
        ?Computer $computer = null,
        ?User $actor = null,
        array $context = [],
    ): void {
        Log::warning('agent_security_event', array_filter([
            'event' => $event,
            'organization_id' => $organization?->id,
            'computer_id' => $computer?->id,
            'actor_user_id' => $actor?->id,
        ] + $this->redact($context), fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function redact(array $context): array
    {
        foreach (array_keys($context) as $key) {
            $lower = strtolower((string) $key);

            if (str_contains($lower, 'secret')
                || str_contains($lower, 'token')
                || str_contains($lower, 'authorization')
                || str_contains($lower, 'bearer')
                || str_contains($lower, 'hash')) {
                $context[$key] = '[redacted]';
            }
        }

        return $context;
    }
}
