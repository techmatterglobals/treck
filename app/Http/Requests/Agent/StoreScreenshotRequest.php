<?php

namespace App\Http\Requests\Agent;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

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

    /**
     * TEMPORARY DIAGNOSTIC (screenshot 422 hunt) — REMOVE once the failing field
     * is identified. Logs exactly which field the agent's multipart upload is
     * being rejected on, plus the server-guessed MIME/size of the image (the
     * `image`/`mimes`/`max` rules check the guessed type, not the client one).
     * Grep the channel with:  grep 'SCREENSHOT-422' storage/logs/laravel.log
     */
    protected function failedValidation(Validator $validator): void
    {
        Log::warning('SCREENSHOT-422 errors', $validator->errors()->toArray());
        Log::info('SCREENSHOT-422 input', $this->except(['image']));
        Log::info('SCREENSHOT-422 files', collect($this->allFiles())->map(function ($file) {
            if (is_array($file)) {
                return 'array of '.count($file).' files';
            }

            return [
                'clientName' => $file->getClientOriginalName(),
                'clientMime' => $file->getClientMimeType(),
                'guessedMime' => $file->isValid() ? $file->getMimeType() : '(invalid upload)',
                'guessedExt' => $file->isValid() ? $file->guessExtension() : null,
                'sizeBytes' => $file->getSize(),
                'isValid' => $file->isValid(),
                'uploadError' => $file->getError(), // 0 = OK; 1/2 = exceeds php.ini limits
            ];
        })->all());

        parent::failedValidation($validator);
    }
}
