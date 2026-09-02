<?php

namespace App\Services\Screenshots;

use App\Models\Screenshot;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Owns everything filesystem-related for screenshots (Phase 8): where bytes are
 * written, how they are read back, and how a time-limited view URL is minted.
 *
 * The bytes live on a configurable Laravel Storage disk (`treck.screenshots.disk`)
 * OUTSIDE the public directory. Clients never receive a filesystem path or a raw
 * disk URL — only a short-lived signed route (`screenshots.image`) that streams
 * the file after the policy check. This keeps storage swappable (local, S3, any
 * future driver) without changing callers.
 */
class ScreenshotStorageService
{
    public function disk(): string
    {
        return (string) config('treck.screenshots.disk', 'local');
    }

    private function filesystem(): Filesystem
    {
        return Storage::disk($this->disk());
    }

    /**
     * Persist the uploaded image and return [storagePath, filename].
     *
     * @return array{0:string,1:string}
     */
    public function store(UploadedFile $file, ?int $organizationId, int $computerId, string $hash, string $capturedAtDate): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = "{$hash}.{$extension}";
        $path = $organizationId
            ? $this->tenantPath($organizationId, $computerId, $capturedAtDate, $filename)
            : $this->legacyPath($computerId, $capturedAtDate, $filename);

        // putFileAs writes to the configured (private) disk.
        $this->filesystem()->putFileAs(dirname($path), $file, basename($path));

        return [$path, $filename];
    }

    /** True if the object still exists on its disk. */
    public function exists(Screenshot $screenshot): bool
    {
        return $this->readablePath($screenshot) !== null;
    }

    /** Delete the underlying image file (best-effort; used on prune). */
    public function delete(Screenshot $screenshot): void
    {
        $this->deleteFrom($screenshot->disk ?: $this->disk(), $screenshot->path);
    }

    /** Delete a raw path on the default screenshot disk (dedup-race rollback). */
    public function deletePath(string $path): void
    {
        $this->deleteFrom($this->disk(), $path);
    }

    private function deleteFrom(string $disk, ?string $path): void
    {
        if ($path && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }

    /**
     * A short-lived, signed URL that streams the image through the authorized
     * route (never a raw disk path). Works identically for local and cloud disks.
     */
    public function signedUrl(Screenshot $screenshot): string
    {
        $minutes = max(1, (int) config('treck.screenshots.url_ttl_minutes', 5));

        return URL::temporarySignedRoute(
            'screenshots.image',
            now()->addMinutes($minutes),
            ['screenshot' => $screenshot->id],
        );
    }

    /** Stream the image bytes for the signed/authorized route. */
    public function response(Screenshot $screenshot): StreamedResponse
    {
        return Storage::disk($screenshot->disk ?: $this->disk())->response($this->readablePath($screenshot) ?? $screenshot->path);
    }

    /** Force a download response for the image (authorized users only). */
    public function download(Screenshot $screenshot): StreamedResponse
    {
        $name = $screenshot->filename ?: basename($screenshot->path);

        return Storage::disk($screenshot->disk ?: $this->disk())->download($this->readablePath($screenshot) ?? $screenshot->path, $name);
    }

    public function tenantPath(int $organizationId, int $computerId, string $capturedAtDate, string $filename): string
    {
        $directory = trim((string) config('treck.screenshots.directory', 'screenshots'), '/');

        return "organizations/{$organizationId}/{$directory}/{$computerId}/{$capturedAtDate}/{$filename}";
    }

    public function legacyPath(int $computerId, string $capturedAtDate, string $filename): string
    {
        $directory = trim((string) config('treck.screenshots.directory', 'screenshots'), '/');

        return "{$directory}/{$computerId}/{$capturedAtDate}/{$filename}";
    }

    public function expectedTenantPath(Screenshot $screenshot): ?string
    {
        if ($screenshot->organization_id === null || $screenshot->computer_id === null || $screenshot->captured_at === null) {
            return null;
        }

        $filename = $screenshot->filename ?: basename($screenshot->path);

        return $this->tenantPath(
            (int) $screenshot->organization_id,
            (int) $screenshot->computer_id,
            $screenshot->captured_at->toDateString(),
            $filename,
        );
    }

    public function expectedLegacyPath(Screenshot $screenshot): ?string
    {
        if ($screenshot->computer_id === null || $screenshot->captured_at === null) {
            return null;
        }

        $filename = $screenshot->filename ?: basename($screenshot->path);

        return $this->legacyPath((int) $screenshot->computer_id, $screenshot->captured_at->toDateString(), $filename);
    }

    private function readablePath(Screenshot $screenshot): ?string
    {
        $disk = $screenshot->disk ?: $this->disk();
        $filesystem = Storage::disk($disk);

        if ($screenshot->path && $filesystem->exists($screenshot->path)) {
            return $screenshot->path;
        }

        if (! config('treck.screenshots.legacy_fallback', true)) {
            return null;
        }

        $legacyPath = $this->expectedLegacyPath($screenshot);
        $tenantPath = $this->expectedTenantPath($screenshot);

        if ($legacyPath !== null && $tenantPath !== null && $legacyPath !== $tenantPath && $filesystem->exists($legacyPath)) {
            Log::warning('Using legacy screenshot storage fallback.', [
                'screenshot_id' => $screenshot->id,
                'organization_id' => $screenshot->organization_id,
                'computer_id' => $screenshot->computer_id,
            ]);

            return $legacyPath;
        }

        return null;
    }
}
