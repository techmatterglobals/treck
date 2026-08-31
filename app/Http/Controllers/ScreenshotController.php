<?php

namespace App\Http\Controllers;

use App\Models\Screenshot;
use App\Services\Screenshots\ScreenshotStorageService;
use App\Services\Tenancy\MonitoringTenantAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-only screenshot management (Phase 8). Thin: page actions render the
 * Livewire dashboard/viewer; the binary actions stream bytes through the storage
 * service. Access is gated by route middleware (`role:admin`) and the
 * ScreenshotPolicy; image bytes are reachable only via a short-lived signed URL,
 * so no filesystem path is ever exposed.
 */
class ScreenshotController extends Controller
{
    use AuthorizesRequests;

    /** The screenshot management dashboard. */
    public function index(): View
    {
        $this->authorize('viewAny', Screenshot::class);

        return view('screenshots.index');
    }

    /** The dedicated screenshot viewer. */
    public function show(Screenshot $screenshot, MonitoringTenantAccess $tenant): View
    {
        $screenshot = $tenant->screenshot($screenshot);
        $this->authorize('view', $screenshot);

        return view('screenshots.show', ['screenshot' => $screenshot]);
    }

    /** Stream the image (signed URL + admin policy). Never exposes a path. */
    public function image(Screenshot $screenshot, ScreenshotStorageService $storage, MonitoringTenantAccess $tenant): StreamedResponse
    {
        $screenshot = $tenant->screenshot($screenshot);
        $this->authorize('view', $screenshot);

        abort_unless($storage->exists($screenshot), 404);

        return $storage->response($screenshot);
    }

    /** Download the image (authorized administrators only). */
    public function download(Screenshot $screenshot, ScreenshotStorageService $storage, MonitoringTenantAccess $tenant): StreamedResponse
    {
        $screenshot = $tenant->screenshot($screenshot);
        $this->authorize('download', $screenshot);

        abort_unless($storage->exists($screenshot), 404);

        return $storage->download($screenshot);
    }
}
