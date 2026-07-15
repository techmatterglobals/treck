<?php

namespace App\Exports;

use App\Enums\ReportPeriod;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Excel export of report rows (maatwebsite/excel). Receives the rows already
 * built by ReportService so the query lives in one place.
 */
class ProductivityReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private readonly Collection $rows,
        private readonly ReportPeriod $period,
    ) {
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->period->label().' report';
    }

    public function headings(): array
    {
        return [
            'Employee',
            'Code',
            'Department',
            'Period',
            'Active (h)',
            'Idle (h)',
            'Active %',
            'Days',
            'Sessions',
        ];
    }

    /** @param  array<string,mixed>  $row */
    public function map($row): array
    {
        return [
            $row['employee_name'],
            $row['employee_code'],
            $row['department'] ?? '—',
            $row['period_label'],
            $row['active_hours'],
            $row['idle_hours'],
            $row['active_ratio'],
            $row['days_present'],
            $row['sessions'],
        ];
    }
}
