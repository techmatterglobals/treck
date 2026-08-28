<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file download observed by the desktop agent (Phase 12) — metadata only,
 * never file contents. Attributed to the employee behind the active Windows
 * account (Phase 11 resolution). Reads run over indexed columns.
 */
class FileDownload extends Model
{
    use HasFactory;

    protected $fillable = [
        'computer_id',
        'employee_id',
        'windows_username',
        'application_name',
        'process_name',
        'window_title',
        'file_name',
        'file_extension',
        'file_size',
        'local_path',
        'download_folder',
        'sha256_hash',
        'downloaded_at',
        'session_id',
        'event_key',
    ];

    protected function casts(): array
    {
        return [
            // Agent records the download instant on-device in UTC (UTC digits).
            'downloaded_at' => UtcDateTime::class,
            'file_size' => 'integer',
        ];
    }

    // ---- Relationships -----------------------------------------------------

    public function computer(): BelongsTo
    {
        return $this->belongsTo(Computer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    // ---- Scopes ------------------------------------------------------------

    public function scopeForEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForComputer(Builder $query, int $computerId): Builder
    {
        return $query->where('computer_id', $computerId);
    }

    public function scopeBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->whereBetween('downloaded_at', [$from, $to]);
    }

    public function scopeOfExtension(Builder $query, string $extension): Builder
    {
        return $query->where('file_extension', ltrim(strtolower($extension), '.'));
    }

    /** Search file name / application / window title. */
    public function scopeMatching(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('file_name', 'like', "%{$term}%")
                ->orWhere('application_name', 'like', "%{$term}%")
                ->orWhere('window_title', 'like', "%{$term}%");
        });
    }

    /** Human-readable file size (e.g. "2.4 MB"). */
    public function sizeLabel(): string
    {
        $bytes = (int) $this->file_size;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) $bytes : number_format($value, 1)).' '.$units[$i];
    }
}
