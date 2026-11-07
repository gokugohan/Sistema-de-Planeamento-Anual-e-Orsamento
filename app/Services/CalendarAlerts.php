<?php

namespace App\Services;

use App\Models\ReportingWindow;
use App\Models\User;
use App\Models\WindowExtension;

/**
 * What the calendar means for one person today: steps that close soon, steps that open soon, steps they have been given
 * more time for, and closed steps in which their unit still has work left. Only the steps that concern them.
 */
class CalendarAlerts
{
    public const SOON_DAYS = 14;

    /** @return array<int, array{tone: string, text: string, url: string, when: int, ask?: array{0: string, 1: int}}> */
    public function for(User $user, int $year): array
    {
        $windows = ReportingWindow::where('fiscal_year', $year)->get();
        $today = now()->startOfDay();
        $alerts = [];
        $service = app(ReportingWindows::class);

        // submitting the plan needs its dates: the administrator is asked to set them, the units are told to prepare meanwhile
        if ($year === \App\Support\FiscalYear::planning() && app(PlanningWindow::class)->isOpen($year) && $service->submissionUndated($year)) { // only for the plan being prepared
            if ($user->isAdministrator()) {
                $alerts[] = ['tone' => 'amber', 'text' => __('Submission of the :year plan has no dates yet, so nobody can submit: the activities stay drafts. Set the dates in the Calendar.', ['year' => $year]), 'url' => route('management.reporting-windows'), 'when' => 0];
            } elseif ($user->can('create_activities') || $user->can('submit_paa')) { // those who prepare
                $alerts[] = ['tone' => 'amber', 'text' => __('Submission of the :year plan has no dates yet. Prepare your activities now; they can be submitted once the administrator sets the dates.', ['year' => $year]), 'url' => route('activities.index'), 'when' => 0];
            }
        }
        // so does reporting: a year being carried out with no reporting dates at all cannot be reported
        if ($year === \App\Support\FiscalYear::execution() && $service->reportingNeedsDates() && ! $windows->contains(fn ($w) => in_array($w->period_type, ['month', 'quarter'], true))) {
            if ($user->isAdministrator()) {
                $alerts[] = ['tone' => 'amber', 'text' => __('Reporting for :year has no dates yet, so the units cannot report. Fill in the dates in the Calendar.', ['year' => $year]), 'url' => route('management.reporting-windows'), 'when' => 0];
            } elseif ($this->concerns($user, 'month')) {
                $alerts[] = ['tone' => 'amber', 'text' => __('Reporting for :year has no dates yet. You can report once the administrator sets them.', ['year' => $year]), 'url' => route('monitoring.report'), 'when' => 0];
            }
        }
        if ($windows->isEmpty()) {
            return $alerts;
        }

        foreach ($windows as $w) {
            if (! $this->concerns($user, $w->period_type)) {
                continue;
            }
            $label = $service->label($w->period_type, (int) $w->period, $year);
            $url = $this->url($w->period_type);
            if ($today->lt($w->opens_on) && $today->diffInDays($w->opens_on) <= self::SOON_DAYS) {
                $alerts[] = ['tone' => 'indigo', 'text' => __(':what opens on :d.', ['what' => $label, 'd' => $w->opens_on->format('d/m/Y')]), 'url' => $url, 'when' => $w->opens_on->timestamp];
            } elseif ($today->between($w->opens_on, $w->closes_on) && $today->diffInDays($w->closes_on) <= self::SOON_DAYS) {
                $left = (int) $today->diffInDays($w->closes_on);
                $alerts[] = [
                    'tone' => $left <= 3 ? 'rose' : 'amber',
                    'text' => $left === 0
                        ? __(':what closes today.', ['what' => $label])
                        : trans_choice(':what closes on :d: :n day left.|:what closes on :d: :n days left.', $left, ['what' => $label, 'd' => $w->closes_on->format('d/m/Y'), 'n' => $left]),
                    'url' => $url, 'when' => $w->closes_on->timestamp,
                ];
            }
        }

        // more time given to the unit of this person
        if (! $user->isAdministrator() && $user->directorate_id) {
            WindowExtension::where('fiscal_year', $year)->whereIn('directorate_id', $service->chain((int) $user->directorate_id))
                ->whereDate('closes_on', '>=', now()->toDateString())->get()
                ->filter(fn ($e) => $this->concerns($user, $e->period_type))
                ->each(function ($e) use (&$alerts, $service, $year) {
                    $alerts[] = [
                        'tone' => 'emerald',
                        'text' => __(':what is open again for your unit until :d.', ['what' => $service->label($e->period_type, (int) $e->period, $year), 'd' => $e->closes_on->format('d/m/Y')]),
                        'url' => $this->url($e->period_type), 'when' => $e->closes_on->timestamp,
                    ];
                });
        }

        // closed, with work left in the person's own units (the administrator gets these from the calendar page)
        if (! $user->isAdministrator()) {
            $scope = app(PlanCycle::class)->scopeFor($user);
            foreach ($service->overdue($year) as $row) {
                if (($scope === null || in_array($row['unit_id'], $scope, true)) && $this->concerns($user, $row['type'])) {
                    $alerts[] = [
                        'tone' => 'rose',
                        'text' => __(':what closed on :d and :unit still has :n :work.', ['what' => $row['label'], 'd' => $row['closed_on']->format('d/m/Y'), 'unit' => $row['unit'], 'n' => $row['count'], 'work' => $row['what']]),
                        'url' => $this->url($row['type']), 'when' => $row['closed_on']->timestamp, 'ask' => [$row['type'], $row['period']],
                    ];
                }
            }
        }

        usort($alerts, fn ($a, $b) => $a['when'] <=> $b['when']);

        return $alerts;
    }

    private function concerns(User $user, string $type): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        return match ($type) {
            ReportingWindows::PLAN, ReportingWindows::REVISION => $user->can('create_activities') || $user->can('submit_paa') || $user->isDirector(),
            ReportingWindows::APPROVAL, ReportingWindows::FINALIZE => $user->isDirector(),
            default => $user->can('update_indicator_progress') || $user->can('approve_progress_report') || $user->isDirector(),
        };
    }

    private function url(string $type): string
    {
        return match ($type) {
            ReportingWindows::APPROVAL, ReportingWindows::FINALIZE => route('approvals.index'),
            'month', 'quarter' => route('monitoring.report'),
            default => route('activities.index'),
        };
    }
}
