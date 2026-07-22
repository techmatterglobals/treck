@component('mail::message')
# {{ $log->title }}

**Severity:** {{ ucfirst($log->severity) }}

{{ $log->message }}

@if ($log->employee || $log->computer || $log->created_at)
@component('mail::table')
| Field | Value |
| :---- | :---- |
@if ($log->employee)| Employee | {{ $log->employee->name }} |
@endif
@if ($log->computer)| Computer | {{ $log->computer->hostname }} |
@endif
| Timestamp | {{ $log->created_at?->format('M j, Y H:i') }} |
@endcomponent
@endif

@component('mail::button', ['url' => $dashboardUrl])
View in dashboard
@endcomponent

Thanks,<br>
{{ config('app.name') }}
@endcomponent
