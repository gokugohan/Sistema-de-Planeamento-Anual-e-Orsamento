<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\ApprovalRules;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The approval chain of an activity, in one place.
 *
 *   Draft/Rejected → Submitted → (Reviewed →) Validated → Waiting Approval → Approved
 *
 * The planner submits; the director of the unit reviews; where the unit sits under another one
 * (a department under a directorate-general) the parent's director validates. Then, in a ministry,
 * the top-level director sends the validated plan to the Minister, who approves. In an institution
 * that is only a directorate or gabinete there is no Minister to ask: the head of the top-level unit
 * (Director / Chefe do Gabinete) gives the final approval. Which of the two applies, and whether a
 * parent director validates sub-units first, is an institution setting (ApprovalRules).
 * Every method re-checks who is asking, so the screens only decide what to show.
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

                return ($parent && ApprovalRules::current()->parentValidation) ? Activity::STATUS_REVIEWED : Activity::STATUS_VALIDATED;

            case Activity::STATUS_REVIEWED:
                return ($admin || ($user->isDirector() && $mine && $mine->id !== $unit?->id && $this->directsUnit($user, $unit)))
                    ? Activity::STATUS_VALIDATED : null;

            case Activity::STATUS_VALIDATED:
                if (! ($admin || $this->isHeadOf($user, $unit))) {
                    return null;
                }

                return ApprovalRules::current()->headApproves() ? Activity::STATUS_APPROVED : Activity::STATUS_WAITING_APPROVAL;
        }

        return null;
    }

    /** The most activities one bulk approval may include. */
    public const BULK_LIMIT = 200;

    /** Move the activity one step up the chain on behalf of $user, with an optional note for the trail. */
    public function advance(User $user, Activity $activity, ?string $note = null): string
    {
        $to = $this->next($user, $activity);
        if (! $to) {
            throw new DomainException(__('It is not your turn to submit this activity or you lack permission.'));
        }
        if ($to === Activity::STATUS_SUBMITTED && ($problems = $this->problems($activity))) {
            throw new DomainException(__('This activity is not ready to submit: ').implode(' ', $problems));
        }

        $stamps = match ($to) {
            Activity::STATUS_APPROVED => ['reviewed_at' => now(), 'reviewed_by' => $user->id, 'approved_at' => now(), 'approved_by' => $user->id],
            Activity::STATUS_SUBMITTED => ['submitted_at' => now()],
            Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED => ['reviewed_at' => now(), 'reviewed_by' => $user->id],
            default => [],
        };
        $activity->auditMetadata = $this->trailMeta($user, $note);
        $activity->update(['status' => $to] + $stamps);

        [$title, $message, $roles, $unitId] = match ($to) {
            Activity::STATUS_SUBMITTED => [__('PAA Submitted for Review'), __('Activities of :dir were submitted by :name for review.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Director'], $activity->directorate_id],
            Activity::STATUS_REVIEWED => [__('PAA Pending Review'), __('Director :name has reviewed an activity of :dir. Pending your validation.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Director'], $activity->directorate->parent_id],
            Activity::STATUS_APPROVED => [__('PAA Activity Approved'), __('Activity ":desc" has been approved.', ['desc' => str($activity->description)->limit(30)]), ['Director', 'Planning'], $activity->directorate_id],
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

        return $user->can('approve_paa') || $this->headApproves($user, $activity) || $this->next($user, $activity) !== null;
    }

    /** Return the activity to its unit. The reason is required: the unit must know what to correct. */
    public function sendBack(User $user, Activity $activity, string $reason): void
    {
        abort_unless($this->canReturn($user, $activity), 403);

        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException(__('Say why the activity is returned, so the unit knows what to correct.'));
        }

        $activity->auditMetadata = ['by' => $user->id, 'reason' => $reason];
        $activity->update(['status' => Activity::STATUS_REJECTED]);
        $this->notify(['Director', 'Planning'], $activity->directorate_id, new SystemNotification(
            __('Activity Returned for Correction'),
            __('Activity ":desc" requires corrections for :year.', ['desc' => str($activity->description)->limit(30), 'year' => $activity->fiscal_year]).' '.__('Reason: :reason', ['reason' => $reason]),
            route('activities.show', ['activity' => $activity->id], false)
        ));
    }

    public function canApprove(User $user, Activity $activity): bool
    {
        return in_array($activity->status, [Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL], true)
            && ! $activity->isLocked()
            && ($user->can('approve_paa') || $this->headApproves($user, $activity));
    }

    public function approve(User $user, Activity $activity, ?string $note = null): void
    {
        abort_unless($this->canApprove($user, $activity), 403);

        $this->markApproved($user, $activity, $this->trailMeta($user, $note));
        $this->notify(['Director', 'Planning'], $activity->directorate_id, new SystemNotification(
            __('PAA Activity Approved'),
            __('Activity ":desc" has been approved.', ['desc' => str($activity->description)->limit(30)]),
            route('activities.show', ['activity' => $activity->id], false)
        ));
    }

    /**
     * Approve many activities at once. Each one is checked again, on its own, right before it is written:
     * a stale selection cannot approve something twice or something that has since been returned, and one
     * that cannot be approved does not stop the others. Every approval is audited with the batch it belonged
     * to, and the units are told once, not once per activity.
     *
     * @param  iterable<int|string>  $ids
     * @return array{batch: string, approved: array<int, int>, skipped: array<int, array{id: int, description: ?string, reason: string}>}
     */
    public function approveMany(User $user, iterable $ids, ?string $note = null): array
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->count() > self::BULK_LIMIT) {
            throw new DomainException(__('Select at most :n activities at a time.', ['n' => self::BULK_LIMIT]));
        }

        $batch = (string) Str::uuid();
        $approved = [];
        $skipped = [];
        $byUnit = [];

        foreach ($ids as $id) {
            try {
                $activity = DB::transaction(function () use ($user, $id, $note, $batch) {
                    $fresh = Activity::query()->with('directorate')->lockForUpdate()->find($id);
                    if (! $fresh) {
                        throw new DomainException(__('Not found.'));
                    }
                    if (! $this->canApprove($user, $fresh)) {
                        throw new DomainException(__('Cannot be approved now (status: :status).', ['status' => __($fresh->status)]));
                    }
                    $this->markApproved($user, $fresh, $this->trailMeta($user, $note) + ['batch' => $batch]);

                    return $fresh;
                });
                $approved[] = $id;
                $byUnit[$activity->directorate_id] = ($byUnit[$activity->directorate_id] ?? 0) + 1;
            } catch (DomainException $e) {
                $skipped[] = ['id' => $id, 'description' => Activity::query()->whereKey($id)->value('description'), 'reason' => $e->getMessage()];
            }
        }

        foreach ($byUnit as $unitId => $count) {
            $unit = Directorate::find($unitId);
            $this->notify(['Director', 'Planning'], $unitId, new SystemNotification(
                __('PAA Activity Approved'),
                trans_choice(':n activity of :dir was approved.|:n activities of :dir were approved.', $count, ['n' => $count, 'dir' => $unit?->name]),
                route('activities.index', absolute: false)
            ));
        }

        if ($approved) {
            AuditLog::create([
                'action' => 'activity.bulk_approved',
                'user_id' => $user->id,
                'description' => count($approved).' activities approved in one batch',
                'metadata' => ['batch' => $batch, 'activity_ids' => $approved, 'skipped' => count($skipped), 'note' => $this->trailNote($note)['note'] ?? null],
            ]);
        }

        return ['batch' => $batch, 'approved' => $approved, 'skipped' => $skipped];
    }

    private function markApproved(User $user, Activity $activity, array $metadata): void
    {
        $activity->auditMetadata = $metadata;
        $activity->update(['status' => Activity::STATUS_APPROVED, 'approved_at' => now(), 'approved_by' => $user->id]);
    }

    /** Who acted (the trail must not depend on who happens to be signed in) and the note, if any. */
    private function trailMeta(User $user, ?string $note): array
    {
        return ['by' => $user->id] + $this->trailNote($note);
    }

    /** @return array<string, string> the note for the audit row, or nothing */
    private function trailNote(?string $note): array
    {
        $note = trim((string) $note);

        return $note === '' ? [] : ['note' => mb_substr($note, 0, 1000)];
    }

    /** Who has to act next on this activity, in words (null once it is approved). */
    public function awaiting(Activity $activity): ?string
    {
        return match ($activity->status) {
            Activity::STATUS_DRAFT, Activity::STATUS_REJECTED => __('The unit to complete and submit it'),
            Activity::STATUS_SUBMITTED => __('The director of the unit to review it'),
            Activity::STATUS_REVIEWED => __('The director of the unit above to validate it'),
            Activity::STATUS_VALIDATED => ApprovalRules::current()->headApproves() ? __('The head of the office to approve it') : __('The top director to send it up for approval'),
            Activity::STATUS_WAITING_APPROVAL => __('The Minister to approve it'),
            default => null,
        };
    }

    public function canReopen(User $user, Activity $activity): bool
    {
        return $activity->status === Activity::STATUS_APPROVED && ! $activity->isLocked()
            && ! app(PlanningWindow::class)->envelopeReceived((int) $activity->fiscal_year) // after the envelope only its distribution changes
            && ($user->can('approve_paa') || $this->headApproves($user, $activity));
    }

    public function reopen(User $user, Activity $activity, ?string $note = null): void
    {
        abort_unless($this->canReopen($user, $activity), 403);

        $activity->auditMetadata = $this->trailMeta($user, $note);
        $activity->update(['status' => Activity::STATUS_DRAFT, 'approved_at' => null, 'approved_by' => null, 'submitted_at' => null]);
    }

    /**
     * When the rules say the head gives the final approval (the default for a directorate or gabinete),
     * the head of the top-level unit approves, but only the plans of their own unit and the units below it.
     */
    private function headApproves(User $user, Activity $activity): bool
    {
        return ApprovalRules::current()->headApproves() && $this->isHeadOf($user, $activity->directorate);
    }

    /** The director of a top-level unit, for that unit and everything below it. */
    private function isHeadOf(User $user, ?Directorate $unit): bool
    {
        return $user->isDirector() && $user->directorate && ! $user->directorate->parent_id && $this->directsUnit($user, $unit);
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
