<?php

namespace App\Http\Requests\Agent;

use App\Enums\AgentEventKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * One event drained from the agent's offline queue (M6).
 *
 * Wire shape (snake_case, matching Treck.Agent.Models.OfflineEventPayload):
 *
 *   {
 *     "kind": "heartbeat" | "session",
 *     "idempotency_key": "<agent-generated unique key>",
 *     "created_at": "<ISO-8601 capture time>",
 *     "payload": "<JSON string produced by the agent>"
 *   }
 *
 * `payload` arrives as an opaque JSON *string* (the agent serializes the inner
 * heartbeat/session object). We only assert it is well-formed JSON here; the
 * service decodes and stores it. The submitting device is resolved from the
 * Sanctum token, never from the body (SEC-1).
 */
class StoreAgentEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(AgentEventKind::values())],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'created_at' => ['required', 'date'],
            'payload' => ['required', 'string', 'json', 'max:65535'],
        ];
    }
}
