<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivitySignature;
use App\Models\AuditLog;
use App\Models\User;

/**
 * The approval trail of one activity: every step it went through, who took it, when, and any note or
 * reason that went with it (including returns and reopenings). It is read from the audit log, which is
 * written on every status change, so it is complete and cannot be edited from the application.
 *
 * Activities that were created before the audit log recorded their steps (or by direct database loads)
 * fall back to the dates and people stored on the activity itself.
 */
class ApprovalTrail
{
    /**
     * @return array<int, array{kind: string, status: ?string, label: string, at: ?\Carbon\CarbonInterface, by: ?string, note: ?string, is_reason: bool, bulk: bool}>
     */
    public function for(Activity $activity): array
    {
        $logs = AuditLog::query()
            ->where('auditable_type', $activity->getMorphClass())
            ->where('auditable_id', $activity->getKey())
            ->whereIn('action', ['activity.created', 'activity.updated'])
            // MySQL can hand the id of a deleted activity to a new one after a restart: rows older than this
            // activity belong to its predecessor, not to it.
            ->when($activity->created_at, fn ($q) => $q->where('created_at', '>=', $activity->created_at->copy()->subSeconds(2)))
            ->orderBy('id')
            ->get();

        $actors = User::query()->whereIn('id', $logs->map(fn ($l) => $l->metadata['by'] ?? $l->user_id)->filter()->unique())->pluck('name', 'id');

        $steps = [];
        foreach ($logs as $log) {
            $by = $actors[$log->metadata['by'] ?? $log->user_id] ?? null;

            if ($log->action === 'activity.created') {
                $steps[] = $this->step('created', Activity::STATUS_DRAFT, __('Created'), $log->created_at, $by);

                continue;
            }

            $new = $log->new_values ?? [];
            if (! array_key_exists('status', $new)) {
                continue; // an edit of the content, not a step of the approval
            }
            $from = $log->old_values['status'] ?? null;
            $to = $new['status'];
            $reason = $log->metadata['reason'] ?? null;

            [$kind, $label] = match (true) {
                $to === Activity::STATUS_REJECTED => ['returned', __('Returned for correction')],
                $from === Activity::STATUS_APPROVED && $to === Activity::STATUS_DRAFT => ['reopened', __('Reopened')],
                default => ['forward', __(['Submitted' => 'Prepared', 'Reviewed' => 'Verified', 'Validated' => 'Certified'][$to] ?? $to)],
            };
            $steps[] = $this->step($kind, $to, $label, $log->created_at, $by, $reason ?? ($log->metadata['note'] ?? null), $reason !== null, isset($log->metadata['batch']));
        }

        // Nothing recorded beyond the creation: use what the activity itself remembers.
        if (count($steps) <= 1) {
            $steps = $this->fromTheActivity($activity);
        }

        return $this->withSignatures($activity, $steps);
    }

    /** The approval, if it happened: who gave it and when. */
    public function approval(Activity $activity): ?array
    {
        $step = collect($this->for($activity))->last(fn ($s) => $s['status'] === Activity::STATUS_APPROVED && $s['kind'] === 'forward');

        return $step && $activity->status === Activity::STATUS_APPROVED ? $step : null;
    }

    /**
     * Where a list signs in order, each signature is a step of its own, with its round: a signature given before the
     * activity was returned or reopened stays in the trail, marked as voided with the reason.
     *
     * @param  array<int, array>  $steps
     * @return array<int, array>
     */
    private function withSignatures(Activity $activity, array $steps): array
    {
        $rows = ActivitySignature::where('activity_id', $activity->id)->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return $steps;
        }
        $names = User::whereIn('id', $rows->pluck('user_id')->merge($rows->pluck('signed_by'))->merge($rows->pluck('closed_by'))->filter()->unique())->pluck('name', 'id');
        foreach ($rows as $s) {
            $by = $names[$s->signed_by ?? $s->user_id] ?? null;
            $note = __('Round :n', ['n' => $s->round]);
            if ($s->signed_by && $s->signed_by !== $s->user_id) {
                $note .= ' · '.__('in the place of :name', ['name' => $names[$s->user_id] ?? '—']);
            }
            if ($s->state === ActivitySignature::VOIDED) {
                $note .= ' · '.__('voided on :date by :name', ['date' => $s->closed_at?->format('d/m/Y'), 'name' => $names[$s->closed_by] ?? '—']).($s->close_reason ? ': '.$s->close_reason : '');
            }
            $steps[] = $this->step('signature', null, __('Signed'), $s->signed_at ?? $s->created_at, $by, $note);
        }

        return collect($steps)->sortBy(fn ($st) => $st['at'])->values()->all();
    }

    /** @return array<int, array> */
    private function fromTheActivity(Activity $a): array
    {
        $steps = [$this->step('created', Activity::STATUS_DRAFT, __('Created'), $a->created_at, null)];
        if ($a->submitted_at) {
            $steps[] = $this->step('forward', Activity::STATUS_SUBMITTED, __('Prepared'), $a->submitted_at, null);
        }
        if ($a->reviewed_at) {
            $steps[] = $this->step('forward', Activity::STATUS_REVIEWED, __('Verified'), $a->reviewed_at, $a->reviewedBy?->name);
        }
        if ($a->approved_at) {
            $steps[] = $this->step('forward', Activity::STATUS_APPROVED, __('Approved'), $a->approved_at, $a->approvedBy?->name);
        }

        return $steps;
    }

    private function step(string $kind, ?string $status, string $label, $at, ?string $by, ?string $note = null, bool $isReason = false, bool $bulk = false): array
    {
        return ['kind' => $kind, 'status' => $status, 'label' => $label, 'at' => $at, 'by' => $by, 'note' => $note, 'is_reason' => $isReason, 'bulk' => $bulk];
    }
}
