<?php

namespace App\Services\Screenshots;

use App\DataObjects\ScreenshotFilter;
use App\Models\Computer;
use App\Models\Screenshot;
use App\Services\Agent\EmployeeResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

/**
 * Screenshot ingest + read model (Phase 8). The controller stays thin; all
 * dedup, storage coordination and reporting live here.
 *
 * Ingest is idempotent per device on the image hash: the SHA-256 is computed
 * server-side from the actual bytes (never trusted from the client), so a
 * re-uploaded or unchanged capture is stored exactly once and the image file is
 * written only for genuinely new content. Reads run over the indexed
 * `screenshots` rows — never a filesystem scan.
 */
class ScreenshotService
{
    public function __construct(
        private readonly ScreenshotStorageService $storage,
        private readonly EmployeeResolver $resolver,
    ) {}

    // ---- Ingest ------------------------------------------------------------

    /**
     * Store one uploaded capture. Returns [screenshot, wasCreated].
     *
     * @param  array<string,mixed>  $data  Validated metadata (captured_at, monitor_number, …).
     * @return array{0:Screenshot,1:bool}
     */
    public function ingest(Computer $computer, array $data, UploadedFile $file): array
    {
        $hash = hash_file('sha256', $file->getRealPath()) ?: '';

        // Duplicate detection: same device + identical bytes → keep the first.
        $existing = Screenshot::forComputer($computer->id)->where('image_hash', $hash)->first();
        if ($existing !== null) {
            return [$existing, false];
        }

        $capturedAt = Carbon::parse($data['captured_at']);
        [$path, $filename] = $this->storage->store($file, $computer->id, $hash, $capturedAt->toDateString());

        [$width, $height] = $this->dimensions($file, $data);

        // Attribute the capture to the employee behind the reported Windows
        // account on shared computers (Phase 11); legacy uploads without a
        // username resolve to the computer's assigned employee.
        $windowsUsername = $data['windows_username'] ?? $data['source_user'] ?? null;
        $employeeId = $this->resolver->resolve($computer, $windowsUsername)->employeeId;

        $attributes = [
            'employee_id' => $employeeId,
            'path' => $path,
            'disk' => $this->storage->disk(),
            'filename' => $filename,
            'captured_at' => $capturedAt,
            'image_hash' => $hash,
            'monitor_number' => (int) ($data['monitor_number'] ?? 0),
            'width' => $width,
            'height' => $height,
            'file_size' => (int) $file->getSize(),
            'active_process' => $data['active_process'] ?? null,
            'active_window_title' => $data['active_window_title'] ?? null,
            'session_id' => $data['session_id'] ?? null,
            'source_session_id' => isset($data['source_session_id']) ? (int) $data['source_session_id'] : null,
            'source_user' => $data['source_user'] ?? null,
            'source_process' => $data['source_process'] ?? null,
            'collection_mode' => $data['collection_mode'] ?? null,
        ];

        try {
            $screenshot = Screenshot::create(['computer_id' => $computer->id] + $attributes);
        } catch (UniqueConstraintViolationException) {
            // Concurrent duplicate won the race: drop our just-written file and
            // return the stored row so the agent still clears its local copy.
            $this->storage->deletePath($path);

            return [
                Screenshot::forComputer($computer->id)->where('image_hash', $hash)->firstOrFail(),
                false,
            ];
        }

        return [$screenshot, true];
    }

    /** Server-side resolution (getimagesize), falling back to agent-reported values. */
    private function dimensions(UploadedFile $file, array $data): array
    {
        $size = @getimagesize($file->getRealPath());

        if (is_array($size)) {
            return [(int) $size[0], (int) $size[1]];
        }

        return [(int) ($data['width'] ?? 0), (int) ($data['height'] ?? 0)];
    }

    // ---- Read model --------------------------------------------------------

    /** Base filtered query (joins employee only for the department filter). */
    public function query(ScreenshotFilter $filter): Builder
    {
        return Screenshot::query()
            ->between($filter->from, $filter->to)
            ->when($filter->employeeId, fn (Builder $q) => $q->forEmployee($filter->employeeId))
            ->when($filter->computerId, fn (Builder $q) => $q->forComputer($filter->computerId))
            ->when($filter->search, fn (Builder $q) => $q->matching($filter->search))
            ->when($filter->departmentId, fn (Builder $q) => $q->whereHas(
                'employee',
                fn (Builder $e) => $e->where('department_id', $filter->departmentId),
            ))
            // Manager/employee visibility restriction (Phase 11); null = unrestricted.
            ->when($filter->employeeIds !== null, fn (Builder $q) => $q->whereIn('employee_id', $filter->employeeIds ?: [0]));
    }

    /** Latest captures (paginated), newest first. */
    public function latest(ScreenshotFilter $filter, int $perPage = 24): LengthAwarePaginator
    {
        return $this->query($filter)
            ->with(['employee.user', 'computer'])
            ->orderByDesc('captured_at')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Headline capture status for the range.
     *
     * @return array{total:int,computers:int,last_capture_at:?Carbon}
     */
    public function status(ScreenshotFilter $filter): array
    {
        $row = $this->query($filter)
            ->selectRaw('COUNT(*) total, COUNT(DISTINCT computer_id) computers, MAX(captured_at) last_capture_at')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'computers' => (int) ($row->computers ?? 0),
            'last_capture_at' => $row?->last_capture_at ? Carbon::parse($row->last_capture_at) : null,
        ];
    }

    /**
     * Neighbours for the viewer's Previous/Next navigation within the same
     * filtered set (ordered by captured_at).
     *
     * @return array{prev:?int,next:?int}
     */
    public function neighbours(ScreenshotFilter $filter, Screenshot $current): array
    {
        // "Next" is newer, "Previous" is older, matching the newest-first grid.
        $next = (clone $this->query($filter))
            ->where('captured_at', '>', $current->captured_at)
            ->orderBy('captured_at')
            ->value('id');

        $prev = (clone $this->query($filter))
            ->where('captured_at', '<', $current->captured_at)
            ->orderByDesc('captured_at')
            ->value('id');

        return ['prev' => $prev ? (int) $prev : null, 'next' => $next ? (int) $next : null];
    }
}
