<?php

namespace App\Exports;

use App\Models\FileDownload;
use App\Support\DisplayTime;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Excel/CSV export of file-download records (Phase 12), using the existing
 * maatwebsite/excel infrastructure. Receives the already-scoped rows so the
 * query + authorization live in one place. Metadata only.
 */
class FileDownloadExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    /** @param  Collection<int,FileDownload>  $rows */
    public function __construct(private readonly Collection $rows) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function title(): string
    {
        return 'File downloads';
    }

    public function headings(): array
    {
        return [
            'Downloaded at', 'Employee', 'Manager', 'Computer', 'Windows user',
            'File name', 'Extension', 'Size (bytes)', 'Application', 'SHA-256',
        ];
    }

    /** @param  FileDownload  $row */
    public function map($row): array
    {
        return [
            DisplayTime::format($row->downloaded_at, 'Y-m-d H:i:s'),
            $row->employee?->name ?? '—',
            $row->employee?->manager?->name ?? '—',
            $row->computer?->hostname ?? '—',
            $row->windows_username ?? '—',
            $row->file_name,
            $row->file_extension,
            (int) $row->file_size,
            $row->application_name ?? '—',
            $row->sha256_hash ?? '',
        ];
    }
}
