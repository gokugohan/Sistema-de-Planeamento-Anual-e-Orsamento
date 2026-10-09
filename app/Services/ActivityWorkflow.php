<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\User;
use App\Notifications\SystemNotification;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * The approval chain of an activity, in one place.
 *
 *   Draft/Rejected → Submitted → (Reviewed →) Validated → Waiting Approval → Approved
 *
 * The planner submits; the director of the unit reviews; where the unit sits under another one
 * (a department under a directorate-general) the parent's director validates; the top-level
 * director sends the validated plan to the Minister, who approves. Every method re-checks who is
 * asking, so the screens only decide what to show.
 */
class ActivityWorkflow
{
    /** Statuses a reviewer may send back to the unit. */
    private const RETURNABLE = [
        Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL,
    ];

    /** Why an activity is not ready to be submitted (empty = ready). */
    public function problems(Activity $activity): array
    {
        $activity->loadMissing(['lineItems', 'indicators']);
        $problems = [];

        if ($activity->lineItems->isEmpty() || $activity->lineItems->sum('estimated_amount') <= 0) {
            $problems[] = __('Add at least one budget line with an amount.');
        }
        if ($activity->indicators->isEmpty()) {
            $problems[] = __('Add at least one indicator with a target.');
        }
        if ($activity->planned_start && $activity->planned_end && $activity->planned_end < $activity->planned_start) {
            $problems[] = __('The end date is before the start date.');
        }

        return $problems;
    }

    /** The status this user would move the activity to, or null if it is not their turn. */
    public function next(User $user, Activity $activity): ?string
    {
        if (! Gate::forUser($user)->allows('view', $activity) || $activity->isLocked()) {
            return null;
        }

        $admin = $user->isAdministrator();
        $unit = $activity->directorate;
        $mine = $user->directorate;

        switch ($activity->status) {
            case Activity::STATUS_DRAFT:
            case Activity::STATUS_REJECTED:
                return $user->can('submit_paa') ? Activity::STATUS_SUBMITTED : null;

            case Activity::STATUS_SUBMITTED:
                if (! $this->directsUnit($user, $unit)) {
                    return null;
                }
                $parent = $admin ? $unit?->parent : $mine?->parent;

                return $parent ? Activity::STATUS_REVIEWED : Activity::STATUS_VALIDATED;

            case Activity::STATUS_REVIEWED:
                return ($admin || ($user->isDirector() && $mine && $mine->id !== $unit?->id && $this->directsUnit($user, $unit)))
                    ? Activity::STATUS_VALIDATED : null;

            case Activity::STATUS_VALIDATED:
                return ($admin || ($user->isDirector() && $mine && ! $mine->parent_id && $this->directsUnit($user, $unit)))
                    ? Activity::STATUS_WAITING_APPROVAL : null;
        }

        return null;
    }

    /** Move the activity one step up the chain on behalf of $user. */
    public function advance(User $user, Activity $activity): string
    {
        $to = $this->next($user, $activity);
        if (! $to) {
            throw new DomainException(__('It is not your turn to submit this activity or you lack permission.'));
        }
        if ($to === Activity::STATUS_SUBMITTED && ($problems = $this->problems($activity))) {
            throw new DomainException(__('This activity is not ready to submit: ').implode(' ', $problems));
        }

        $stamps = match ($to) {
            Activity::STATUS_SUBMITTED => ['submitted_at' => now()],
            Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED => ['reviewed_at' => now(), 'reviewed_by' => $user->id],
            default => [],
        };
        $activity->update(['status' => $to] + $stamps);

        [$title, $message, $roles, $unitId] = match ($to) {
            Activity::STATUS_SUBMITTED => [__('PAA Submitted for Review'), __('Activities of :dir were submitted by :name for review.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Director'], $activity->directorate_id],
            Activity::STATUS_REVIEWED => [__('PAA Pending Review'), __('Director :name has reviewed an activity of :dir. Pending your validation.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Director'], $activity->directorate->parent_id],
            Activity::STATUS_VALIDATED => [__('PAA Validated'), __('An activity of :dir was validated by :name.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Planning'], $activity->directorate_id],
            default => [__('PAA Pending Final Approval'), __('The plan of :dir was sent to the Minister by :name.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Minister'], null],
        };
        $this->notify($roles, $unitId, new SystemNotification($title, $message, route('activities.show', ['activity' => $activity->id], false)));

        return $to;
    }

    public function canReturn(User $user, Activity $activity): bool
    {
        if (! in_array($activity->status, self::RETURNABLE, true) || $activity->isLocked()) {
            return false;
        }

        return $user->can('approve_paa') || $this->next($user, $activity) !== null;
    }

    public function sendBack(User $user, Activity $activity): void
    {
        abort_unless($this->canReturn($user, $activity), 403);

        $activity->update(['status' => Activity::STATUS_REJECTED]);
        $this->notify(['Director', 'Planning'], $activity->directorate_id, new SystemNotification(
            __('Activity Returned for Correction'),
            __('Activity ":desc" requires corrections for :year.', ['desc' => str($activity->description)->limit(30), 'year' => $activity->fiscal_year]),
            route('activities.show', ['activity' => $activity->id], false)
        ));
    }

    public function canApprove(User $user, Activity $activity): bool
    {
        return $user->can('approve_paa')
            && in_array($activity->status, [Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL], true)
            && ! $activity->isLocked();
    }

    public function approve(User $user, Activity $activity): void
    {
        abort_unless($this->canApprove($user, $activity), 403);

        $activity->update(['status' => Activity::STATUS_APPROVED, 'approved_at' => now(), 'approved_by' => $user->id]);
        $this->notify(['Director', 'Planning'], $activity->directorate_id, new SystemNotification(
            __('PAA Activity Approved'),
            __('Activity ":desc" has been approved.', ['desc' => str($activity->description)->limit(30)]),
            route('activities.show', ['activity' => $activity->id], false)
        ));
    }

    public function canReopen(User $user, Activity $activity): bool
    {
        return $user->can('approve_paa') && $activity->status === Activity::STATUS_APPROVED && ! $activity->isLocked();
    }

    public function reopen(User $user, Activity $activity): void
    {
        abort_unless($this->canReopen($user, $activity), 403);

        $activity->update(['status' => Activity::STATUS_DRAFT, 'approved_at' => null, 'approved_by' => null, 'submitted_at' => null]);
    }

    /** Is the user's own unit the activity's unit or one above it? Admins direct every unit. */
    private function directsUnit(User $user, ?Directorate $unit): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }
        if (! $unit || ! $user->isDirector() || ! $user->directorate_id) {
            return false;
        }

        return in_array($unit->id, Directorate::find($user->directorate_id)?->getSelfAndDescendantIds() ?? [], true);
    }

    private function notify(array $roles, ?int $unitId, SystemNotification $notification): void
    {
        User::role($roles)->when($unitId, fn ($q) => $q->where('directorate_id', $unitId))->get()
            ->each(fn ($u) => $u->notify($notification));
    }
}
