<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivitySignature;
use App\Models\ApprovalDelegation;
use App\Models\ApproverAssignment;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Policies\ActivityPolicy;
use App\Support\ApprovalRules;
use App\Support\PlanOptions;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The approval chain of an activity, in one place.
 *
 *   Draft/Rejected → Submitted → (Reviewed →) Validated → Waiting Approval → Approved
 *
 * The technical officer submits; the director of the unit certifies; where the unit sits under another one
 * (a department under a directorate-general) the parent's director validates. The final approval is given by
 * the head of the top-level unit (the directorate), or by the people named for it in the approval lists. The
 * Minister does not approve plans. Whether a parent director validates sub-units first, and whether finance
 * confirms the budget, are institution settings (ApprovalRules).
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
        } elseif ($activity->indicators->contains(fn ($i) => trim((string) $i->name) === '' || trim((string) $i->unit) === '' || $i->annual_target === null)) {
            // the same needs as the form and the import: every indicator has a name, a unit and a target (zero is a target)
            $problems[] = __('Every indicator needs a name, a unit of measure and a target.');
        }
        // fields the institution made mandatory as well (an optional rule; none by default)
        $missing = [];
        foreach (PlanOptions::current()->requiredFields as $field) {
            $empty = match ($field) {
                'indicator_baseline' => $activity->indicators->contains(fn ($i) => blank($i->baseline)),
                'indicator_means' => $activity->indicators->contains(fn ($i) => blank($i->means_of_verification)),
                default => blank($activity->getAttribute($field)),
            };
            if ($empty) {
                $missing[] = __(PlanOptions::FIELDS[$field]);
            }
        }
        if ($missing) {
            $problems[] = __('Fill in: :fields.', ['fields' => implode(', ', $missing)]);
        }
        if ($activity->planned_start && $activity->planned_end && $activity->planned_end < $activity->planned_start) {
            $problems[] = __('The end date is before the start date.');
        }

        return $problems;
    }

    /** The status this user would move the activity to, or null if it is not their turn. */
    public function next(User $user, Activity $activity): ?string
    {
        $to = $this->target($user, $activity);
        if ($to !== null && $to !== Activity::STATUS_SUBMITTED && $this->selfActionRefused($user, $activity)) {
            return null;
        }
        if ($to === Activity::STATUS_APPROVED && $this->externalApprovalMissing($activity)) {
            return null;
        }

        return $to !== null && ! $this->inTurn($user, $activity) ? null : $to;
    }

    /** Did this user prepare the activity (the chain has gone past the preparing)? */
    private function preparedBy(User $user, Activity $activity): bool
    {
        return $activity->submitted_by !== null && (int) $activity->submitted_by === $user->id
            && ! in_array($activity->status, [Activity::STATUS_DRAFT, Activity::STATUS_REJECTED], true);
    }

    /** The institution keeps preparer and approver apart (block mode) and this user would be both. Administrators are not refused; theirs is marked. */
    private function selfActionRefused(User $user, Activity $activity): bool
    {
        return ApprovalRules::current()->segregation === ApprovalRules::SEGREGATION_BLOCK && ! $user->isAdministrator() && $this->preparedBy($user, $activity);
    }

    /** What the trail records when the preparer also acts on the activity further up the chain (flag mode, or an administrator's override). */
    private function selfActionMeta(User $user, Activity $activity): array
    {
        return ApprovalRules::current()->segregation !== ApprovalRules::SEGREGATION_OFF && $this->preparedBy($user, $activity)
            ? ['segregation' => 'exception: the preparer also acted on this activity'] : [];
    }

    // ── lists that sign in order: everyone named signs, one after the other, and only the last signature moves it on

    /** The list that governs the stage the activity is in (null: no list, or a stage without one). */
    private function stageList(Activity $activity): ?array
    {
        $lists = app(ApproverLists::class);

        return match ($activity->status) {
            Activity::STATUS_SUBMITTED => $lists->listFor($activity->directorate_id, ApproverAssignment::CERTIFY),
            Activity::STATUS_REVIEWED => $lists->listAbove($activity->directorate_id, ApproverAssignment::CERTIFY),
            Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL => $lists->listFor($activity->directorate_id, ApproverAssignment::APPROVE),
            default => null,
        };
    }

    /** Is it this user's turn to sign? Always, unless the list of the stage signs in order and somebody else is next. Administrators are never held up. */
    private function inTurn(User $user, Activity $activity): bool
    {
        $list = $this->stageList($activity);
        $lists = app(ApproverLists::class);

        return $user->isAdministrator() || ! $lists->sequential($list) || $lists->inTurn($user, $list, $activity->id, $activity->status);
    }

    /**
     * Record this user's signature for the stage. Null when the stage is complete (no in-order list, an administrator,
     * or the last name signed): the activity moves on. Otherwise the names still to sign, and the activity stays.
     */
    private function signStage(User $user, Activity $activity): ?string
    {
        $list = $this->stageList($activity);
        $lists = app(ApproverLists::class);
        $clear = fn () => ActivitySignature::close($activity->id, ActivitySignature::COMPLETED, $user->id, null, $activity->status);

        if (! $lists->sequential($list) || $user->isAdministrator()) {
            $clear();

            return null;
        }
        $slot = $lists->nextSigner($list, $activity->id, $activity->status);
        $signature = new ActivitySignature(['activity_id' => $activity->id, 'stage' => $activity->status, 'round' => ActivitySignature::currentRound($activity->id), 'user_id' => $slot->user_id, 'signed_by' => $user->id, 'signed_at' => now()]);
        $signature->auditMetadata = ['by' => $user->id, 'activity' => $activity->id, 'stage' => $activity->status];
        $signature->save();

        $left = $lists->unsigned($list, $activity->id, $activity->status);
        if ($left->isEmpty()) {
            $clear();

            return null;
        }
        $left->first()->user?->notify(new SystemNotification(
            __('Your signature is next'),
            __('Activity ":desc" of :dir waits for your signature.', ['desc' => str($activity->description)->limit(30), 'dir' => $activity->directorate?->name]),
            route('activities.show', ['activity' => $activity->id], false)
        ));

        return $left->map(fn ($a) => $a->user?->name)->filter()->implode(', ');
    }

    private function target(User $user, Activity $activity): ?string
    {
        if (! Gate::forUser($user)->allows('view', $activity) || $activity->isLocked()) {
            return null;
        }

        $admin = $user->isAdministrator();
        $unit = $activity->directorate;
        $mine = $user->directorate;
        $lists = app(ApproverLists::class);

        // where the institution asks for it, nothing is decided until the whole plan of the directorate has been submitted
        if (in_array($activity->status, [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL], true)
            && app(PlanStatus::class)->holdBack($activity)) {
            return null;
        }

        // outside the calendar for deciding on the annual plan only an administrator (or a unit it has reopened it for) decides;
        // revisions of approved activities are not new planning and are not held back by it
        if (! $admin && (int) $activity->version === 0
            && in_array($activity->status, [Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL], true)
            && app(ReportingWindows::class)->closedReason($user, (int) $activity->fiscal_year, ReportingWindows::APPROVAL, 1, $activity->directorate_id)) {
            return null;
        }

        switch ($activity->status) {
            case Activity::STATUS_DRAFT:
            case Activity::STATUS_REJECTED:
                // outside the submission window of the annual plan only an administrator submits
                if (! $admin && app(ReportingWindows::class)->planClosedReasonFor($user, $activity)) {
                    return null;
                }

                // a list of preparers decides who submits; without one, whoever may submit
                return match ($lists->designated($user, $activity->directorate_id, ApproverAssignment::PREPARE)) {
                    true => Activity::STATUS_SUBMITTED,
                    false => $admin && $user->can('submit_paa') ? Activity::STATUS_SUBMITTED : null,
                    null => $user->can('submit_paa') ? Activity::STATUS_SUBMITTED : null,
                };

            case Activity::STATUS_SUBMITTED:
                // where the institution asks for it, the budget is confirmed by finance before anyone certifies
                if (! $this->budgetConfirmed($activity)) {
                    return null;
                }
                $listed = $lists->designated($user, $activity->directorate_id, ApproverAssignment::CERTIFY);
                if ($listed === false && ! $admin) {
                    return null;
                }
                if ($listed !== true && ! $this->directsUnit($user, $unit)) {
                    return null;
                }
                $parent = ($admin || $listed) ? $unit?->parent : $user->seatFor($unit)?->parent;

                return ($parent && ApprovalRules::current()->parentValidation) ? Activity::STATUS_REVIEWED : Activity::STATUS_VALIDATED;

            case Activity::STATUS_REVIEWED:
                // the unit above validates: its own list of certifiers if it has one, otherwise its director
                $above = $lists->designatedAbove($user, $activity->directorate_id, ApproverAssignment::CERTIFY);
                if ($above !== null) {
                    return ($admin || $above) ? Activity::STATUS_VALIDATED : null;
                }

                return ($admin || ($user->isDirector() && ($seat = $user->seatFor($unit)) && $seat->id !== $unit?->id))
                    ? Activity::STATUS_VALIDATED : null;

            case Activity::STATUS_VALIDATED:
                // the final approval is the head of the directorate's (or whoever its list of approvers names)
                $listed = $lists->designated($user, $activity->directorate_id, ApproverAssignment::APPROVE);
                if (! ($admin || $listed === true || ($listed === null && $this->isHeadOf($user, $unit)))) {
                    return null;
                }

                return Activity::STATUS_APPROVED;
        }

        return null;
    }

    /** Has the budget of this activity been confirmed, where the institution asks for that? (always true where it does not) */
    public function budgetConfirmed(Activity $activity): bool
    {
        return ! ApprovalRules::current()->budgetConfirmation || $activity->budget_confirmed_at !== null;
    }

    /** May this user confirm the budget of the activity now? A finance officer of the unit (or an administrator), while it waits for certification. */
    public function canConfirmBudget(User $user, Activity $activity): bool
    {
        return ApprovalRules::current()->budgetConfirmation
            && $activity->status === Activity::STATUS_SUBMITTED
            && ! $activity->isLocked()
            && ($user->isAdministrator() || $user->can('confirm_budget'))
            && app(ActivityPolicy::class)->inScope($user, $activity->directorate_id);
    }

    /** The finance officer confirms the budget lines as they stand, with a short justification of the resource plan. */
    public function confirmBudget(User $user, Activity $activity, ?string $justification = null): void
    {
        abort_unless($this->canConfirmBudget($user, $activity), 403);
        if ($activity->lineItems()->doesntExist()) {
            throw new DomainException(__('There is no budget line to confirm.'));
        }

        $activity->auditMetadata = ['by' => $user->id, 'budget' => 'confirmed'] + $this->trailNote($justification);
        $activity->update(['budget_confirmed_at' => now(), 'budget_confirmed_by' => $user->id, 'budget_justification' => trim((string) $justification) ?: null]);
    }

    /** The most activities one bulk approval may include. */
    public const BULK_LIMIT = 200;

    /** Move the activity one step up the chain on behalf of $user, with an optional note for the trail. */
    public function advance(User $user, Activity $activity, ?string $note = null): string
    {
        // the check, the new status and the version are one unit of work, on the row as it is now
        $signed = false;
        $to = DB::transaction(function () use ($user, $activity, $note, &$signed) {
            $this->lock($activity);

            $to = $this->next($user, $activity);
            if (! $to) {
                throw new DomainException(__('It is not your turn to submit this activity or you lack permission.'));
            }
            if ($to === Activity::STATUS_SUBMITTED && ($problems = $this->problems($activity))) {
                throw new DomainException(__('This activity is not ready to submit: ').implode(' ', $problems));
            }

            if ($this->signStage($user, $activity) !== null) {
                $signed = true; // signed; the next name on the list is still to sign

                return $activity->status;
            }

            $stamps = match ($to) {
                Activity::STATUS_APPROVED => ['reviewed_at' => now(), 'reviewed_by' => $user->id, 'approved_at' => now(), 'approved_by' => $user->id],
                Activity::STATUS_SUBMITTED => ['submitted_at' => now(), 'submitted_by' => $user->id],
                Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED => ['reviewed_at' => now(), 'reviewed_by' => $user->id],
                default => [],
            };
            $activity->auditMetadata = $this->trailMeta($user, $note) + $this->selfActionMeta($user, $activity);
            $activity->update(['status' => $to] + $stamps);
            if ($to === Activity::STATUS_APPROVED) {
                app(ActivityVersions::class)->approved($activity, $user);
            }

            return $to;
        });
        if ($signed) {
            return $to;
        }

        [$title, $message, $roles, $unitId] = match ($to) {
            Activity::STATUS_SUBMITTED => [__('PAA Submitted for Review'), __('Activities of :dir were submitted by :name for review.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Director'], $activity->directorate_id],
            Activity::STATUS_REVIEWED => [__('PAA Pending Review'), __('Director :name has reviewed an activity of :dir. Pending your validation.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Director'], $activity->directorate->parent_id],
            Activity::STATUS_APPROVED => [__('PAA Activity Approved'), __('Activity ":desc" has been approved.', ['desc' => str($activity->description)->limit(30)]), ['Director', 'Planning'], $activity->directorate_id],
            Activity::STATUS_VALIDATED => [__('PAA Validated'), __('An activity of :dir was validated by :name.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Planning'], $activity->directorate_id],
            default => [__('PAA Validated'), __('An activity of :dir was validated by :name.', ['name' => $user->name, 'dir' => $activity->directorate->name]), ['Planning'], $activity->directorate_id],
        };
        $notification = new SystemNotification($title, $message, route('activities.show', ['activity' => $activity->id], false));
        $notified = $this->notify($roles, $unitId, $notification);
        if ($to !== Activity::STATUS_APPROVED) {
            $this->notifyHolders($activity->fresh(), $notification, $notified); // whoever holds the next step, named in a list or appointed as interim
        }

        return $to;
    }

    public function canReturn(User $user, Activity $activity): bool
    {
        if (! in_array($activity->status, self::RETURNABLE, true) || $activity->isLocked()) {
            return false;
        }

        return $user->isAdministrator() || $this->headApproves($user, $activity) || $this->isListedApprover($user, $activity) || $this->canConfirmBudget($user, $activity) || $this->next($user, $activity) !== null;
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
        ActivitySignature::close($activity->id, ActivitySignature::VOIDED, $user->id, $reason); // kept on record; the next signatures belong to the next round
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
            && $this->mayGiveFinalApproval($user, $activity)
            && ! $this->selfActionRefused($user, $activity)
            && ! $this->externalApprovalMissing($activity)
            && $this->inTurn($user, $activity);
    }

    /**
     * A revision marked as needing an approval outside the institution (an optional rule) is approved only once the
     * reference of that approval is recorded.
     */
    public function externalApprovalMissing(Activity $activity): bool
    {
        return PlanOptions::current()->externalReference && $activity->external_approval_required && blank($activity->external_approval_reference);
    }

    /** May this user give the final approval of the activity (whatever its status now)? For the outside-approval reference. */
    public function isFinalApprover(User $user, Activity $activity): bool
    {
        return $this->mayGiveFinalApproval($user, $activity);
    }

    /** The final approval: a list of approvers for the unit decides if there is one (administrators always may), otherwise the rules. */
    private function mayGiveFinalApproval(User $user, Activity $activity): bool
    {
        $listed = app(ApproverLists::class)->designated($user, $activity->directorate_id, ApproverAssignment::APPROVE);

        return $user->isAdministrator() || ($listed ?? $this->headApproves($user, $activity));
    }

    private function isListedApprover(User $user, Activity $activity): bool
    {
        return app(ApproverLists::class)->designated($user, $activity->directorate_id, ApproverAssignment::APPROVE) === true;
    }

    public function approve(User $user, Activity $activity, ?string $note = null): void
    {
        $approved = DB::transaction(function () use ($user, $activity, $note) {
            $this->lock($activity);
            abort_unless($this->canApprove($user, $activity), 403);

            if ($this->signStage($user, $activity) !== null) {
                return false; // signed; the next name on the list is still to sign
            }
            $this->markApproved($user, $activity, $this->trailMeta($user, $note) + $this->selfActionMeta($user, $activity));

            return true;
        });
        if (! $approved) {
            return;
        }
        $this->notify(['Director', 'Planning'], $activity->directorate_id, new SystemNotification(
            __('PAA Activity Approved'),
            __('Activity ":desc" has been approved.', ['desc' => str($activity->description)->limit(30)]),
            route('activities.show', ['activity' => $activity->id], false)
        ));
    }

    /** Lock the activity row and take its current state, so two people acting at once cannot both pass the same check. */
    private function lock(Activity $activity): void
    {
        $current = Activity::query()->whereKey($activity->getKey())->lockForUpdate()->firstOrFail();
        $activity->setRawAttributes($current->getAttributes(), true);
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
                $waiting = null;
                $activity = DB::transaction(function () use ($user, $id, $note, $batch, &$waiting) {
                    $fresh = Activity::query()->with('directorate')->lockForUpdate()->find($id);
                    if (! $fresh) {
                        throw new DomainException(__('Not found.'));
                    }
                    if (! $this->canApprove($user, $fresh)) {
                        throw new DomainException(__('Cannot be approved now (status: :status).', ['status' => __($fresh->status)]));
                    }
                    if ($waiting = $this->signStage($user, $fresh)) {
                        return $fresh;
                    }
                    $this->markApproved($user, $fresh, $this->trailMeta($user, $note) + $this->selfActionMeta($user, $fresh) + ['batch' => $batch]);

                    return $fresh;
                });
                if ($waiting) {
                    $skipped[] = ['id' => $id, 'description' => $activity->description, 'reason' => __('Your signature is recorded; still to sign: :names.', ['names' => $waiting])];

                    continue;
                }
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
        app(ActivityVersions::class)->approved($activity, $user);
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
        if ($activity->status === Activity::STATUS_SUBMITTED && ! $this->budgetConfirmed($activity)) {
            return __('The finance officer to confirm the budget');
        }
        $named = fn (string $step) => app(ApproverLists::class)->names($activity, $step);
        $who = match ($activity->status) {
            Activity::STATUS_DRAFT, Activity::STATUS_REJECTED => $named(ApproverAssignment::PREPARE),
            Activity::STATUS_SUBMITTED => $named(ApproverAssignment::CERTIFY),
            Activity::STATUS_REVIEWED => app(ApproverLists::class)->namesAbove($activity, ApproverAssignment::CERTIFY),
            Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL => $named(ApproverAssignment::APPROVE),
            default => null,
        };
        $list = $this->stageList($activity);
        if ($list !== null && app(ApproverLists::class)->sequential($list)) {
            $left = app(ApproverLists::class)->unsigned($list, $activity->id, $activity->status);
            if ($left->isNotEmpty()) {
                return __('Signing in order: :name is next (:done of :total signed)', ['name' => $left->first()->user?->name, 'done' => $list['people']->count() - $left->count(), 'total' => $list['people']->count()]);
            }
        }
        if ($who) {
            return __('Named in the approval list: :names', ['names' => $who]);
        }

        return match ($activity->status) {
            Activity::STATUS_DRAFT, Activity::STATUS_REJECTED => __('The unit to complete and submit it'),
            Activity::STATUS_SUBMITTED => __('The director of the unit to review it'),
            Activity::STATUS_REVIEWED => __('The director of the unit above to validate it'),
            Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL => __('The head of the directorate to approve it'),
            default => null,
        };
    }

    /**
     * Can this user start a revision of an approved activity? Whoever prepares the unit's activities (its list of
     * preparers, or anyone who may submit for the unit), the director of the unit, whoever gives its final approval,
     * and administrators. The revision goes through the whole chain again, so starting one approves nothing.
     */
    public function canReopen(User $user, Activity $activity): bool
    {
        if ($activity->status !== Activity::STATUS_APPROVED || $activity->isLocked() || ! Gate::forUser($user)->allows('view', $activity)) {
            return false;
        }
        if ($user->isAdministrator() || $this->mayGiveFinalApproval($user, $activity) || $this->directsUnit($user, $activity->directorate)) {
            return true;
        }

        return match (app(ApproverLists::class)->designated($user, $activity->directorate_id, ApproverAssignment::PREPARE)) {
            true => true,
            false => false,
            null => $user->can('submit_paa'),
        };
    }

    /**
     * Start a revision of an approved activity: it goes back to Draft with the reason, and through the whole approval
     * chain again. The approved version stays in force (its amounts are untouched) until the revision is approved and
     * becomes the next version. Signatures of the round in progress, if any, are kept on record as voided.
     */
    public function reopen(User $user, Activity $activity, ?string $note = null): void
    {
        abort_unless($this->canReopen($user, $activity), 403);
        $reason = trim((string) $note);
        if (mb_strlen($reason) < 3) {
            throw new DomainException(__('Say why the activity is being revised.'));
        }

        $activity->auditMetadata = $this->trailMeta($user, $reason) + ['revision' => (int) $activity->version + 1];
        $activity->update(['status' => Activity::STATUS_DRAFT, 'revision_reason' => mb_substr($reason, 0, 2000), 'approved_at' => null, 'approved_by' => null, 'submitted_at' => null, 'external_approval_required' => false, 'external_approval_reference' => null]);
        ActivitySignature::close($activity->id, ActivitySignature::VOIDED, $user->id, $reason);
    }

    /**
     * When the rules say the head gives the final approval (the default for a directorate or gabinete),
     * the head of the top-level unit approves, but only the plans of their own unit and the units below it.
     */
    private function headApproves(User $user, Activity $activity): bool
    {
        return $this->isHeadOf($user, $activity->directorate);
    }

    /** The director of a top-level unit, for that unit and everything below it. */
    private function isHeadOf(User $user, ?Directorate $unit): bool
    {
        $seat = $user->seatFor($unit);

        return $seat && ! $seat->parent_id;
    }

    /** Is the user's own unit the activity's unit or one above it? Admins direct every unit. */
    private function directsUnit(User $user, ?Directorate $unit): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }
        if (! $unit) {
            return false;
        }

        return in_array($unit->id, $user->directedUnitIds(), true);
    }

    /** @return array<int, int> the ids of the users told */
    private function notify(array $roles, ?int $unitId, SystemNotification $notification): array
    {
        $users = User::role($roles)->when($unitId, fn ($q) => $q->where('directorate_id', $unitId))->get();
        $users->each(fn ($u) => $u->notify($notification));

        return $users->pluck('id')->all();
    }

    /**
     * Tell those who can act on the activity now and were not told yet: people named in an approval list, appointed as
     * interim, or directing the unit or a unit above it. Only people who really hold the next step are told.
     *
     * @param  array<int, int>  $already
     */
    private function notifyHolders(Activity $activity, SystemNotification $notification, array $already): void
    {
        $chain = [];
        for ($id = $activity->directorate_id, $guard = 0; $id && $guard++ < 20; $id = Directorate::whereKey($id)->value('parent_id')) {
            $chain[] = $id;
        }

        $candidateIds = ApproverAssignment::whereIn('directorate_id', $chain)->pluck('user_id')
            ->merge(ApprovalDelegation::active()->whereIn('directorate_id', $chain)->pluck('user_id'))
            ->merge(User::role('Director')->whereIn('directorate_id', $chain)->pluck('id'))
            ->unique()->diff($already);

        User::with('directorate')->whereIn('id', $candidateIds)->get()
            ->filter(fn (User $u) => $this->next($u, $activity) !== null || $this->canApprove($u, $activity))
            ->each(fn (User $u) => $u->notify($notification));
    }
}
