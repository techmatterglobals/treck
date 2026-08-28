<?php

namespace App\Services\Screenshots;

use App\Models\Screenshot;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
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
    public function store(UploadedFile $file, int $computerId, string $hash, string $capturedAtDate): array
    {
        $directory = trim((string) config('treck.screenshots.directory', 'screenshots'), '/');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $filename = "{$hash}.{$extension}";
        $path = "{$directory}/{$computerId}/{$capturedAtDate}/{$filename}";

        // putFileAs writes to the configured (private) disk.
        $this->filesystem()->putFileAs(dirname($path), $file, basename($path));

        return [$path, $filename];
    }

    /** True if the object still exists on its disk. */
    public function exists(Screenshot $screenshot): bool
    {
        return Storage::disk($screenshot->disk ?: $this->disk())->exists($screenshot->path);
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
        return Storage::disk($screenshot->disk ?: $this->disk())->response($screenshot->path);
    }

    /** Force a download response for the image (authorized users only). */
    public function download(Screenshot $screenshot): StreamedResponse
    {
        $name = $screenshot->filename ?: basename($screenshot->path);

        return Storage::disk($screenshot->disk ?: $this->disk())->download($screenshot->path, $name);
    }
}
