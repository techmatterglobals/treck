<?php

namespace App\Services\Productivity;

use App\Enums\ProductivityRating;
use App\Enums\ReportPeriod;
use App\Models\ActivityLog;
use App\Models\ApplicationUsage;
use App\Models\ProductivityReport;
use Illuminate\Support\Carbon;

/**
 * Generates daily `productivity_reports` from activity_logs + application_usage.
 *
 * If app-usage classification exists for the day, the score is
 * productive / active. Otherwise it falls back to the active-ratio proxy
 * (active / active+idle) — consistent with the dashboard/report proxy — so the
 * report is meaningful even before app tracking is enabled.
 */
class ProductivityService
{
    public function generateDaily(Carbon|string|null $date = null): int
    {
        $date = $date ? Carbon::parse($date) : today();
        $dateStr = $date->toDateString();

        $employeeIds = ActivityLog::whereDate('work_date', $dateStr)
            ->distinct()
            ->pluck('employee_id');

        foreach ($employeeIds as $employeeId) {
            $activity = ActivityLog::whereDate('work_date', $dateStr)
                ->where('employee_id', $employeeId)
                ->selectRaw('MAX(organization_id) as organization_id, COALESCE(SUM(active_seconds),0) a, COALESCE(SUM(idle_seconds),0) i')
                ->first();

            $active = (int) $activity->a;
            $idle = (int) $activity->i;

            $usage = ApplicationUsage::whereDate('used_at', $dateStr)
                ->where('employee_id', $employeeId)
                ->selectRaw('
                    COALESCE(SUM(CASE WHEN productivity = ? THEN duration_seconds END),0) p,
                    COALESCE(SUM(CASE WHEN productivity = ? THEN duration_seconds END),0) u,
                    COALESCE(SUM(CASE WHEN productivity = ? THEN duration_seconds END),0) n', [
                    ProductivityRating::Productive->value,
                    ProductivityRating::Unproductive->value,
                    ProductivityRating::Neutral->value,
                ])
                ->first();

            $productive = (int) $usage->p;
            $unproductive = (int) $usage->u;
            $neutral = (int) $usage->n;

            if (($productive + $unproductive + $neutral) > 0) {
                $score = $active > 0 ? min(100, round($productive / $active * 100, 2)) : 0.0;
            } else {
                // Fallback proxy: no app-usage data yet.
                $productive = $active;
                $unproductive = 0;
                $neutral = 0;
                $score = ($active + $idle) > 0 ? round($active / ($active + $idle) * 100, 2) : 0.0;
            }

            ProductivityReport::updateOrCreate(
                [
                    'employee_id' => $employeeId,
                    'period_type' => ReportPeriod::Daily->value,
                    'period_date' => $dateStr,
                ],
                [
                    'organization_id' => $activity->organization_id !== null ? (int) $activity->organization_id : null,
                    'active_seconds' => $active,
                    'productive_seconds' => $productive,
                    'unproductive_seconds' => $unproductive,
                    'neutral_seconds' => $neutral,
                    'productivity_score' => $score,
                ],
            );
        }

        return $employeeIds->count();
    }
}
