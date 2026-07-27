<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One screenshot uploaded from the agent's offline queue (Phase 8).
 *
 * Unlike JSON events, screenshots are binary, so they use a dedicated
 * multipart endpoint (`POST /api/agent/screenshots`) — but the same device
 * authentication (Sanctum `agent:report`), the same offline-first delivery, and
 * the same idempotency guarantees. The submitting device is resolved from the
 * token, never from the body (SEC-1); `employee_id` is taken from the Computer.
 */
class StoreScreenshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = max(64, (int) config('treck.screenshots.max_upload_kb', 8192));

        return [
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png', "max:{$maxKb}"],
            'captured_at' => ['required', 'date'],
            'monitor_number' => ['nullable', 'integer', 'min:0', 'max:64'],
            'width' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'height' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'image_hash' => ['nullable', 'string', 'size:64'],
            'active_process' => ['nullable', 'string', 'max:255'],
            'active_window_title' => ['nullable', 'string', 'max:255'],
            'session_id' => ['nullable', 'string', 'max:64'],

            // Event source metadata (Phase 8 #3): where the capture was collected.
            'source_session_id' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'source_user' => ['nullable', 'string', 'max:255'],
            // Explicit Windows-identity alias for the employee resolver (Phase 11).
            'windows_username' => ['nullable', 'string', 'max:255'],
            'source_process' => ['nullable', 'string', 'max:255'],
            'collection_mode' => ['nullable', 'string', 'max:32'],
        ];
    }
}
