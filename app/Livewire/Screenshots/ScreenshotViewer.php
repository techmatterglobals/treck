<?php

namespace App\Livewire\Screenshots;

use App\DataObjects\ScreenshotFilter;
use App\Models\Screenshot;
use App\Services\Screenshots\ScreenshotService;
use App\Services\Screenshots\ScreenshotStorageService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

/**
 * Dedicated screenshot viewer (Phase 8): full preview plus capture metadata
 * (employee, computer, timestamp, active application, window title, resolution,
 * file size), Previous/Next navigation, and a download link for authorized
 * users. The image and download are reached only through signed / authorized
 * routes — never a filesystem path.
 *
 * Previous/Next navigate within the same computer's captures (newest-first),
 * which is the most intuitive context regardless of how the viewer was reached.
 */
class ScreenshotViewer extends Component
{
    public int $screenshotId;

    public function mount(Screenshot $screenshot): void
    {
        // Super Admin: any capture. Manager: only their team's (Phase 11).
        abort_unless(auth()->user()?->can('view', $screenshot) ?? false, 403);

        $this->screenshotId = $screenshot->id;
    }

    public function render(ScreenshotService $service, ScreenshotStorageService $storage): View
    {
        $screenshot = Screenshot::with(['employee.user', 'computer'])->findOrFail($this->screenshotId);

        // Navigate within this computer's captures across all time.
        $filter = new ScreenshotFilter(
            from: Carbon::create(2000, 1, 1),
            to: now()->addDay(),
            computerId: $screenshot->computer_id,
        );

        $neighbours = $service->neighbours($filter, $screenshot);

        return view('livewire.screenshots.screenshot-viewer', [
            'screenshot' => $screenshot,
            'imageUrl' => $storage->signedUrl($screenshot),
            'prevId' => $neighbours['prev'],
            'nextId' => $neighbours['next'],
        ]);
    }
}
