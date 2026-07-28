<?php

namespace App\Services\Presence;

use App\Models\AgentEvent;
use App\Models\FileDownload;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Projects one completed file-download event (Phase 12) into `file_downloads`.
 * Called from the ingest pipeline only for newly stored `file_download` events,
 * so it runs once per unique event; it is additionally idempotent on
 * (computer_id, event_key). Metadata only — file contents are never read.
 *
 * The agent sends PascalCase keys (folded through SourceStamp): FileName,
 * FileExtension, FileSize, LocalPath, DownloadFolder, Sha256Hash, DownloadedAt,
 * ApplicationName, ProcessName, WindowTitle, SessionId, SourceUser.
 */
class FileDownloadProjector
{
    public function project(AgentEvent $event): ?FileDownload
    {
        $payload = $event->payload ?? [];

        $fileName = trim((string) $this->value($payload, ['FileName', 'file_name'], ''));
        if ($fileName === '') {
            return null; // nothing meaningful to record
        }

        $extension = $this->value($payload, ['FileExtension', 'file_extension'], null);
        if (blank($extension)) {
            $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: null;
        }
        $extension = $extension !== null ? strtolower(ltrim((string) $extension, '.')) : null;

        return FileDownload::firstOrCreate(
            [
                'computer_id' => $event->computer_id,
                'event_key' => $event->idempotency_key,
            ],
            [
                'employee_id' => $event->employee_id,
                'windows_username' => $this->string($this->value($payload, ['SourceUser', 'WindowsUsername', 'windows_username'], null), 191),
                'application_name' => $this->string($this->value($payload, ['ApplicationName', 'application_name'], null), 255),
                'process_name' => $this->string($this->value($payload, ['ProcessName', 'process_name'], null), 255),
                'window_title' => $this->string($this->value($payload, ['WindowTitle', 'window_title'], null), 255),
                'file_name' => $this->sanitize($fileName, 255),
                'file_extension' => $extension !== null ? Str::limit($extension, 32, '') : null,
                'file_size' => max(0, (int) $this->value($payload, ['FileSize', 'file_size'], 0)),
                'local_path' => $this->string($this->value($payload, ['LocalPath', 'local_path'], null), 1024),
                'download_folder' => $this->string($this->value($payload, ['DownloadFolder', 'download_folder'], null), 1024),
                'sha256_hash' => $this->hash($this->value($payload, ['Sha256Hash', 'sha256_hash', 'Sha256'], null)),
                'downloaded_at' => $this->toTime($this->value($payload, ['DownloadedAt', 'downloaded_at'], null)) ?? $event->occurred_at,
                'session_id' => $this->string($this->value($payload, ['SessionId', 'session_id'], null), 64),
            ],
        );
    }

    private function hash(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return preg_match('/^[A-Fa-f0-9]{64}$/', $value) ? strtolower($value) : null;
    }

    private function string(mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->sanitize((string) $value, $max);
    }

    private function sanitize(string $value, int $max): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return Str::limit(trim($clean), $max, '');
    }

    private function toTime(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $keys
     */
    private function value(array $payload, array $keys, mixed $default): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $payload)) {
                return $payload[$key];
            }
        }

        return $default;
    }
}
