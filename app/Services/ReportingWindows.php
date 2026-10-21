<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\ReportingWindow;
use App\Models\User;
use App\Models\WindowExtension;
use App\Notifications\SystemNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The calendar of the plan: the dates between which the annual plan is submitted (type 'plan'), decided on
 * ('approval') and finalized ('finalize'), a revision of an approved activity is made ('revision'), a month
 * (executed amount, monthly narrative) or a quarter (indicators, quarterly narrative) is reported.
 * A period with no window is open; with a window it is open from its first to its last day, and an
 * administrator can always act. Verifying is not limited by windows, only reporting is.
 *
 * A period that has closed can be reopened by an administrator for one unit (and the units below it) until a date,
 * so that one department that is late does not reopen the period for everybody.
 */
class ReportingWindows
{
    public const PLAN = 'plan';

    /** Deciding on submitted activities: certify, validate, approve. */
    public const APPROVAL = 'approval';

    /** Finalizing the plan of a directorate. */
    public const FINALIZE = 'finalize';

    /** Revisions of approved activities: no window means they can be made at any time (an optional window). */
    public const REVISION = 'revision';

    /** @var array<string, bool> */
    private array $reopened = [];

    public function find(int $year, string $type, int $period): ?ReportingWindow
    {
        return ReportingWindow::where(['fiscal_year' => $year, 'period_type' => $type, 'period' => $period])->first();
    }

    /** $unitId is the unit the act is for (the activity's unit); without it, the unit of the user. */
    public function allows(?User $user, int $year, string $type, int $period, ?int $unitId = null): bool
    {
        if ($user?->isAdministrator()) {
            return true;
        }
        $window = $this->find($year, $type, $period);
        if (! $window || (now()->startOfDay()->gte($window->opens_on) && now()->startOfDay()->lte($window->closes_on))) {
            return true;
        }

        return $this->reopenedFor($year, $type, $period, $unitId ?? $user?->directorate_id);
    }

    /** Has an administrator reopened this period for the unit, or a unit above it, and is it still running? */
    public function reopenedFor(int $year, string $type, int $period, ?int $unitId): bool
    {
        if (! $unitId) {
            return false;
        }
        $key = "$year/$type/$period/$unitId";

        return $this->reopened[$key] ??= WindowExtension::where(['fiscal_year' => $year, 'period_type' => $type, 'period' => $period])
            ->whereIn('directorate_id', $this->chain($unitId))->whereDate('closes_on', '>=', now()->toDateString())->exists();
    }

    /** The unit and the units above it. @return array<int, int> */
    public function chain(int $unitId): array
    {
        $parents = Directorate::pluck('parent_id', 'id');
        $ids = [];
        for ($n = 0; $unitId && $n < 20 && ! in_array($unitId, $ids, true); $n++) {
            $ids[] = $unitId;
            $unitId = (int) ($parents[$unitId] ?? 0);
        }

        return $ids;
    }

    /** A sentence for the screen when the period is closed, or null when it is open. */
    public function closedReason(?User $user, int $year, string $type, int $period, ?int $unitId = null): ?string
    {
        if ($this->allows($user, $year, $type, $period, $unitId)) {
            return null;
        }
        $w = $this->find($year, $type, $period);
        $before = now()->startOfDay()->lt($w->opens_on);
        $on = ['d' => ($before ? $w->opens_on : $w->closes_on)->format('d/m/Y')];

        return match ($type) {
            self::REVISION => $before ? __('Revisions of approved activities open on :d.', $on) : __('Revisions of approved activities closed on :d.', $on),
            self::PLAN => $before ? __('Submission of the annual plan opens on :d.', $on) : __('Submission of the annual plan closed on :d.', $on),
            self::APPROVAL => $before ? __('Deciding on the annual plan opens on :d.', $on) : __('Deciding on the annual plan closed on :d.', $on),
            self::FINALIZE => $before ? __('Finalizing the plan opens on :d.', $on) : __('Finalizing the plan closed on :d.', $on),
            default => $before ? __('Reporting for this period opens on :d.', $on) : __('Reporting for this period closed on :d.', $on),
        };
    }

    /** Why the annual plan of this year cannot be created, edited or submitted now (null when it can). */
    public function planClosedReason(?User $user, int $year, ?int $unitId = null): ?string
    {
        return $this->closedReason($user, $year, self::PLAN, 1, $unitId);
    }

    /**
     * The same for one activity. A revision of an approved activity (version above 0) is not new planning, so it is not
     * held back by the submission window of the annual plan: changes after approval are exactly what comes later.
     */
    public function planClosedReasonFor(?User $user, Activity $activity): ?string
    {
        return (int) $activity->version > 0
            ? $this->closedReason($user, (int) $activity->fiscal_year, self::REVISION, 1, $activity->directorate_id) // its own window, if the institution set one
            : $this->planClosedReason($user, (int) $activity->fiscal_year, $activity->directorate_id);
    }

    // ── late units: reopening the calendar for one of them

    /**
     * Periods that have closed with work left undone, unit by unit: plans not submitted, activities not decided on,
     * plans not finalized, months without an executed amount, quarters without indicator figures.
     * Units whose period has been reopened are not listed.
     *
     * @return array<int, array{type: string, period: int, label: string, unit_id: int, unit: string, count: int, closed_on: \Illuminate\Support\Carbon, what: string}>
     */
    public function overdue(int $year): array
    {
        $windows = ReportingWindow::where('fiscal_year', $year)->whereDate('closes_on', '<', now()->toDateString())->orderBy('closes_on')->get();
        if ($windows->isEmpty()) {
            return [];
        }
        $units = Directorate::pluck('name', 'id');
        $rows = [];

        foreach ($windows as $w) {
            $byUnit = match ($w->period_type) {
                self::PLAN => $this->countByUnit($year, [Activity::STATUS_DRAFT, Activity::STATUS_REJECTED], 0),
                self::APPROVAL => $this->countByUnit($year, [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL], 0),
                self::FINALIZE => $this->notFinalized($year),
                'month' => $this->withoutExecuted($year, (int) $w->period),
                'quarter' => $this->withoutProgress($year, (int) $w->period),
                default => [],
            };
            $what = match ($w->period_type) {
                self::PLAN => __('activities not submitted'),
                self::APPROVAL => __('activities waiting for a decision'),
                self::FINALIZE => __('plan not finalized'),
                'month' => __('activities without an executed amount'),
                default => __('activities without indicator figures'),
            };
            foreach ($byUnit as $unitId => $count) {
                if ($count > 0 && ! $this->reopenedFor($year, $w->period_type, (int) $w->period, (int) $unitId)) {
                    $rows[] = [
                        'type' => $w->period_type, 'period' => (int) $w->period, 'label' => $this->label($w->period_type, (int) $w->period, $year),
                        'unit_id' => (int) $unitId, 'unit' => (string) ($units[$unitId] ?? '—'), 'count' => (int) $count, 'closed_on' => $w->closes_on, 'what' => $what,
                    ];
                }
            }
        }

        return $rows;
    }

    public function label(string $type, int $period, int $year): string
    {
        return match ($type) {
            self::PLAN => __('Annual plan').' · '.__('submission'),
            self::APPROVAL => __('Annual plan').' · '.__('approval'),
            self::FINALIZE => __('Annual plan').' · '.__('finalizing'),
            self::REVISION => __('Revisions of approved activities'),
            'month' => Carbon::create($year, max(1, min(12, $period)), 1)->translatedFormat('F'),
            default => 'Q'.$period,
        };
    }

    /** @param  array<int, string>  $statuses  @return array<int, int> activities per unit */
    private function countByUnit(int $year, array $statuses, int $version): array
    {
        return Activity::where('fiscal_year', $year)->whereIn('status', $statuses)->where('version', $version)
            ->groupBy('directorate_id')->selectRaw('directorate_id, COUNT(*) AS n')->pluck('n', 'directorate_id')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int, int> top-level units that planned and have not finalized (1 each) */
    private function notFinalized(int $year): array
    {
        $planning = app(PlanningWindow::class);
        $closed = DB::table('unit_plannings')->where('fiscal_year', $year)->pluck('directorate_id')->all();

        return $planning->plannedRoots($year)->reject(fn ($id) => in_array($id, $closed, true))->mapWithKeys(fn ($id) => [$id => 1])->all();
    }

    /** @return array<int, int> approved activities, per unit, that have no executed amount for the month */
    private function withoutExecuted(int $year, int $month): array
    {
        return Activity::where('fiscal_year', $year)->where('status', Activity::STATUS_APPROVED)->where('version', '>', 0)
            ->whereHas('lineItems.adjustedBudget')
            ->whereDoesntHave('lineItems.executions', fn ($q) => $q->where('month', $month))
            ->groupBy('directorate_id')->selectRaw('directorate_id, COUNT(*) AS n')->pluck('n', 'directorate_id')->map(fn ($n) => (int) $n)->all();
    }

    /** @return array<int, int> approved activities with indicators, per unit, that have no figure for the quarter */
    private function withoutProgress(int $year, int $quarter): array
    {
        return Activity::where('fiscal_year', $year)->where('status', Activity::STATUS_APPROVED)->where('version', '>', 0)
            ->whereHas('indicators')
            ->whereDoesntHave('indicators.progress', fn ($q) => $q->where('quarter', $quarter))
            ->groupBy('directorate_id')->selectRaw('directorate_id, COUNT(*) AS n')->pluck('n', 'directorate_id')->map(fn ($n) => (int) $n)->all();
    }

    /** An administrator reopens a closed period for one unit, until a date, with a reason (it stays in the audit trail). */
    public function reopen(User $admin, int $year, string $type, int $period, int $unitId, string $closesOn, ?string $reason): WindowExtension
    {
        abort_unless($admin->isAdministrator(), 403);

        $extension = WindowExtension::updateOrCreate(
            ['fiscal_year' => $year, 'period_type' => $type, 'period' => $period, 'directorate_id' => $unitId],
            ['closes_on' => $closesOn, 'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null, 'created_by' => $admin->id]
        );
        $this->reopened = [];

        // the people of that unit hear about it
        $unit = Directorate::find($unitId);
        User::whereIn('directorate_id', $this->below($unitId))->get()->each(fn (User $u) => $u->notify(new SystemNotification(
            __('The calendar was reopened for your unit'),
            __(':what is open again for :unit until :d.', ['what' => $this->label($type, $period, $year), 'unit' => $unit?->name, 'd' => $extension->closes_on->format('d/m/Y')]),
            route('work.home', [], false)
        )));

        return $extension;
    }

    /** The unit and the units below it. @return array<int, int> */
    private function below(int $unitId): array
    {
        return Directorate::find($unitId)?->getSelfAndDescendantIds() ?? [$unitId];
    }

    public function withdraw(int $extensionId): void
    {
        WindowExtension::whereKey($extensionId)->delete();
        $this->reopened = [];
    }

    /** A user of a late unit asks the administrators to reopen a closed period (once a day per person and period). */
    public function requestReopening(User $user, int $year, string $type, int $period): bool
    {
        if (! $user->directorate_id) {
            return false;
        }
        if (! Cache::add("reopen-request:{$user->id}:$year:$type:$period", 1, now()->addDay())) {
            return false;
        }
        $unit = Directorate::find($user->directorate_id);
        $url = route('management.reporting-windows', [], false);
        User::role('Admin')->get()->each(fn (User $admin) => $admin->notify(new SystemNotification(
            __('A unit asks for the calendar to be reopened'),
            __(':user (:unit) asks to reopen :what.', ['user' => $user->name, 'unit' => $unit?->name, 'what' => $this->label($type, $period, $year)]),
            $url
        )));

        return true;
    }
}
