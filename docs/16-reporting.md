# 16. Reporting Module

Daily / weekly / monthly productivity reports, filterable by employee,
department, and date range, with **Excel** and **PDF** export.

## 16.1 Packages

```bash
composer require maatwebsite/excel      # Excel export
composer require barryvdh/laravel-dompdf # PDF export
```

Both auto-register via package discovery. Optionally publish Excel's config:
`php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config`.

## 16.2 Delivered files

| File | Purpose |
| ---- | ------- |
| `app/Enums/ReportPeriod.php` | daily / weekly / monthly |
| `app/DataObjects/ReportFilter.php` | Typed, defaulted filter (period, employee, department, from, to) |
| `app/Services/Reporting/ReportService.php` | The aggregation queries + totals |
| `app/Exports/ProductivityReportExport.php` | Laravel Excel export (FromCollection + WithMapping) |
| `app/Http/Requests/ReportFilterRequest.php` | Filter validation + `view reports` authorization |
| `app/Http/Controllers/ReportController.php` | `index`, `exportExcel`, `exportPdf` |
| `resources/views/reports/index.blade.php` | Filter form + summary + table |
| `resources/views/reports/pdf.blade.php` | Dompdf template |
| `routes/modules/reports.php` | Routes (gated by `view reports`) |

Include the routes: `require __DIR__.'/modules/reports.php';` in `routes/web.php`.

## 16.3 Routes

| Verb | URI | Name | Action |
| ---- | --- | ---- | ------ |
| GET | `/reports` | `reports.index` | Filter form + results table |
| GET | `/reports/export/excel` | `reports.export.excel` | Download `.xlsx` |
| GET | `/reports/export/pdf` | `reports.export.pdf` | Download `.pdf` |

All three take the **same query params** (`period`, `employee_id`,
`department_id`, `from`, `to`), so the export buttons simply re-use the current
filter via `route('reports.export.excel', request()->query())`.

## 16.4 Filters

`ReportFilter::fromArray()` normalizes the validated request with defaults:
period → `daily`, range → current month. Filters:

- **Period** — `daily` (per day), `weekly` (ISO week), `monthly` (per month).
- **Employee** — optional; single employee.
- **Department** — optional; all employees in a department.
- **Date range** — `from` / `to` (`to` validated `after_or_equal:from`).

## 16.5 Queries

`ReportService::build()` runs one grouped query over `activity_logs`, joined to
`employees`/`users`/`departments`, filtered by range + employee + department,
and grouped by employee **and a period bucket**:

| Period | SQL bucket |
| ------ | ---------- |
| Daily | `DATE_FORMAT(work_date, '%Y-%m-%d')` |
| Weekly | `DATE_FORMAT(work_date, '%x-W%v')` (ISO year-week) |
| Monthly | `DATE_FORMAT(work_date, '%Y-%m')` |

Each row returns summed `active_seconds`/`idle_seconds`, session count, distinct
days present, and a computed **active ratio** (`active / (active + idle)`) —
the productivity proxy, swappable for `productivity_reports` later without
changing the row shape. `totals()` reduces the rows to a summary line.

## 16.6 Export

- **Excel** — `ProductivityReportExport` implements `FromCollection`,
  `WithHeadings`, `WithMapping`, `ShouldAutoSize`, `WithTitle`. The controller
  builds the rows via `ReportService` and hands them to the export, so Excel and
  the on-screen table always agree. `Excel::download($export, $filter->fileSlug().'.xlsx')`.
- **PDF** — `Pdf::loadView('reports.pdf', …)->setPaper('a4','landscape')->download(…)`.
  The PDF Blade is plain inline-styled HTML (dompdf can't use Tailwind/Livewire).

Filenames are derived from the filter, e.g.
`treck-weekly-report-2026-07-01_2026-07-15.xlsx`.

## 16.7 Authorization

Every route is gated by `permission:view reports`, and `ReportFilterRequest::
authorize()` re-checks it — so both the page and the export endpoints require
the permission.

## 16.8 Try it

```bash
composer require maatwebsite/excel barryvdh/laravel-dompdf
php artisan serve
```

Sign in as an admin → `/reports`:
- Pick **Weekly**, a department, and a date range → **Apply**.
- **Export Excel** / **Export PDF** download the same filtered data.
