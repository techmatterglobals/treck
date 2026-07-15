<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Treck {{ $filter->period->label() }} Report</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #111; margin: 24px; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        .meta { color: #555; font-size: 10px; margin-bottom: 12px; }
        .totals { margin: 10px 0 16px; }
        .totals span { display: inline-block; margin-right: 18px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 5px 7px; text-align: left; }
        th { background: #f3f4f6; }
        td.num, th.num { text-align: right; }
        tr:nth-child(even) td { background: #fafafa; }
    </style>
</head>
<body>
    <h1>Treck — {{ $filter->period->label() }} Productivity Report</h1>
    <div class="meta">
        Range: {{ $filter->from->toDateString() }} to {{ $filter->to->toDateString() }}
        &nbsp;|&nbsp; Generated: {{ now()->toDayDateTimeString() }}
    </div>

    <div class="totals">
        <span><strong>Rows:</strong> {{ $totals['rows'] }}</span>
        <span><strong>Active:</strong> {{ $totals['active_hours'] }} h</span>
        <span><strong>Idle:</strong> {{ $totals['idle_hours'] }} h</span>
        <span><strong>Active %:</strong> {{ $totals['active_ratio'] }}%</span>
    </div>

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Code</th>
                <th>Department</th>
                <th>Period</th>
                <th class="num">Active (h)</th>
                <th class="num">Idle (h)</th>
                <th class="num">Active %</th>
                <th class="num">Days</th>
                <th class="num">Sessions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['employee_name'] }}</td>
                    <td>{{ $row['employee_code'] }}</td>
                    <td>{{ $row['department'] ?? '-' }}</td>
                    <td>{{ $row['period_label'] }}</td>
                    <td class="num">{{ $row['active_hours'] }}</td>
                    <td class="num">{{ $row['idle_hours'] }}</td>
                    <td class="num">{{ $row['active_ratio'] }}%</td>
                    <td class="num">{{ $row['days_present'] }}</td>
                    <td class="num">{{ $row['sessions'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;">No activity for the selected filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
