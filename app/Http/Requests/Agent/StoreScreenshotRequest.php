<?php

namespace App\Http\Requests\Agent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

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

    /**
     * Free-text foreground metadata (window title, process, source identity) is
     * cosmetic context, not integrity data — an over-length value must never
     * cost us the screenshot. Clamp each to its column limit here, so the
     * `max:*` rules below pass on truncated input instead of returning 422.
     * The agent also truncates client-side; this is the server-side backstop.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'active_window_title' => $this->clamp($this->input('active_window_title'), 255),
            'active_process' => $this->clamp($this->input('active_process'), 255),
            'source_process' => $this->clamp($this->input('source_process'), 255),
            'source_user' => $this->clamp($this->input('source_user'), 255),
            'windows_username' => $this->clamp($this->input('windows_username'), 255),
            'collection_mode' => $this->clamp($this->input('collection_mode'), 32),
        ], static fn ($value) => $value !== null));
    }

    /** Truncate a string field to a character limit (mb-aware); null-safe. */
    private function clamp(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Str::limit($value, $max, '');
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
