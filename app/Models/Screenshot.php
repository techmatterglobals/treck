<?php

namespace App\Models;

use App\Services\Screenshots\ScreenshotStorageService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One captured screenshot. Original scaffolding kept only path/thumbnail_path;
 * Phase 8 adds full capture metadata (disk, hash, resolution, size, foreground
 * context, session). The image bytes live on `disk` at `path` (outside public/)
 * and are served only through a signed, authorized route via
 * {@see ScreenshotStorageService}. Deduplicated per
 * device on (computer_id, image_hash).
 */
class Screenshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'computer_id',
        'activity_log_id',
        'path',
        'thumbnail_path',
        'captured_at',
        'disk',
        'filename',
        'image_hash',
        'monitor_number',
        'width',
        'height',
        'file_size',
        'active_process',
        'active_window_title',
        'session_id',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'monitor_number' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'file_size' => 'integer',
        ];
    }

    // ----------------------------------------------------------------
    // Relationships
    // ----------------------------------------------------------------

    /** The employee the screenshot belongs to (N:1). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The computer the screenshot was captured on (N:1). */
    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    /** The PC session the screenshot was captured in (N:1, optional). */
    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class);
    }

    // ----------------------------------------------------------------
    // Accessors
    // ----------------------------------------------------------------

    /** Public URL of the full screenshot. */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->path ? Storage::url($this->path) : null,
        );
    }

    /** Public URL of the thumbnail (falls back to the full image). */
    protected function thumbnailUrl(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::url($this->thumbnail_path ?? $this->path),
        );
    }

    /** Human-readable resolution, e.g. "1920×1080". */
    protected function resolution(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->width && $this->height) ? "{$this->width}\u{00D7}{$this->height}" : '—',
        );
    }

    // ----------------------------------------------------------------
    // Scopes
    // ----------------------------------------------------------------

    /** Screenshots for a specific employee. */
    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    /** Screenshots for a specific computer. */
    public function scopeForComputer(Builder $query, int $computerId): Builder
    {
        return $query->where('computer_id', $computerId);
    }

    /** Screenshots captured on a specific date (defaults to today). */
    public function scopeForDate(Builder $query, $date = null): Builder
    {
        return $query->whereDate('captured_at', $date ?? today());
    }

    /** Captures whose time falls in an inclusive [from, to] range. */
    public function scopeBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('captured_at', [$from, $to]);
    }

    /** Match a foreground process / window-title search term. */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('active_process', 'like', "%{$term}%")
                ->orWhere('active_window_title', 'like', "%{$term}%");
        });
    }
}
