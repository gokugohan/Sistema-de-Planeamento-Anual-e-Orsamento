<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BudgetCycle;
use App\Models\FiscalEnvelope;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;

/**
 * Whether new activities can still be planned for a fiscal year.
 *
 * Planning ends with the final approval of the plan: the final approver (the Minister of a ministry, the
 * director or chefe do gabinete of a directorate or office) or an administrator finalizes it once nothing
 * is waiting for a decision. Only they can reopen it, and they must say why; both steps are in the audit
 * trail.
 *
 * Once the budget envelope has been received (approved) the plan is final for good: it cannot be reopened
 * and only the distribution of the envelope can still change. The same goes for a closed year. A year whose
 * envelope was received without the plan ever being finalized counts as ended too.
 *
 * Before that, existing activities keep following their own rules (an approved one is changed by
 * reopening it, by the final approver or an administrator, with a reason).
 */
class PlanningWindow
{
    public const FINALIZED = 'closed';

    public const REOPENED = 'open';

    /** @var array<int, string> statuses of an activity that is waiting for somebody's decision */
    private const PENDING = [
        Activity::STATUS_SUBMITTED, Activity::STATUS_REVIEWED, Activity::STATUS_VALIDATED, Activity::STATUS_WAITING_APPROVAL,
    ];

    /** 'Closed' (the year is closed), 'Finalized' (final approval given), 'Approved' (the budget is approved), or null while open. */
    public function endedBy(int $year): ?string
    {
        return $this->verdict(
            FiscalEnvelope::where('fiscal_year', $year)->value('status'),
            BudgetCycle::where('fiscal_year', $year)->value('planning_state'),
        );
    }

    public function verdict(?string $envelopeStatus, ?string $planningState): ?string
    {
        if ($envelopeStatus === FiscalEnvelope::STATUS_CLOSED) {
            return 'Closed';
        }
        if ($envelopeStatus === FiscalEnvelope::STATUS_APPROVED) {
            return 'Approved'; // the envelope is received: from here only its distribution changes
        }

        return $planningState === self::FINALIZED ? 'Finalized' : null;
    }

    /** The first year from this one on whose plan is still open: where new activities go (two queries for the three years). */
    public function nextOpenYear(): int
    {
        $current = (int) date('Y');
        $years = [$current, $current + 1, $current + 2];
        $envelopes = FiscalEnvelope::whereIn('fiscal_year', $years)->pluck('status', 'fiscal_year');
        $states = BudgetCycle::whereIn('fiscal_year', $years)->pluck('planning_state', 'fiscal_year');

        foreach ($years as $year) {
            if ($this->verdict($envelopes[$year] ?? null, $states[$year] ?? null) === null) {
                return $year;
            }
        }

        return $current + 3;
    }

    /** Has the budget envelope been received (or the year closed)? Then the plan can no longer change at all. */
    public function envelopeReceived(int $year): bool
    {
        return in_array($this->endedBy($year), ['Approved', 'Closed'], true);
    }

    public function isOpen(int $year): bool
    {
        return $this->endedBy($year) === null;
    }

    /**
     * Who can finalize or reopen the plan of a year: an administrator, or the head of a top-level unit. With a year
     * given, a head can do it only when every activity of that year is in their own directorate (the only directorate
     * there is); where several directorates plan, ending the year is an administrator's, not one directorate's.
     * Without a year (for the units a head already stands for) the head of any top-level unit counts.
     */
    public function canDecide(User $user, ?int $year = null): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }
        $seats = $user->seats()->filter(fn ($d) => ! $d->parent_id);
        if ($seats->isEmpty()) {
            return false;
        }
        if ($year === null) {
            return true;
        }
        $own = $seats->flatMap(fn ($d) => $d->getSelfAndDescendantIds())->unique()->values()->all();

        return ! Activity::where('fiscal_year', $year)->whereNotIn('directorate_id', $own)->exists();
    }

    public function pending(int $year): int
    {
        return Activity::where('fiscal_year', $year)->whereIn('status', self::PENDING)->count();
    }

    /** @return array{ended: ?string, by: ?string, at: ?CarbonInterface, remark: ?string, pending: int} */
    public function state(int $year): array
    {
        $cycle = BudgetCycle::where('fiscal_year', $year)->first();

        return [
            'ended' => $this->verdict(FiscalEnvelope::where('fiscal_year', $year)->value('status'), $cycle?->planning_state),
            'by' => $cycle?->planning_by ? User::whereKey($cycle->planning_by)->value('name') : null,
            'at' => $cycle?->planning_at,
            'remark' => $cycle?->planning_remark,
            'pending' => $this->pending($year),
        ];
    }

    /** The final approval of the plan: planning for the year ends. */
    public function finalize(User $user, int $year, ?string $remark = null): void
    {
        abort_unless($this->canDecide($user, $year), 403);

        if ($this->envelopeReceived($year)) {
            throw new DomainException(__('The budget for :year has been received, so its plan is final: only the distribution of the envelope can still change.', ['year' => $year]));
        }
        if ($this->isOpen($year) === false) {
            throw new DomainException(__('The plan for :year is already finalized.', ['year' => $year]));
        }
        if ($pending = $this->pending($year)) {
            throw new DomainException(trans_choice(
                ':n activity is still waiting for a decision. Decide on it before finalizing the plan.|:n activities are still waiting for a decision. Decide on them before finalizing the plan.',
                $pending, ['n' => $pending]
            ));
        }
        if (! Activity::where('fiscal_year', $year)->where('status', Activity::STATUS_APPROVED)->exists()) {
            throw new DomainException(__('There is no approved activity to finalize.'));
        }

        $this->write($user, $year, self::FINALIZED, $remark);
    }

    /** Open planning again, for the final approver or an administrator, with a remark. */
    public function reopen(User $user, int $year, string $remark): void
    {
        abort_unless($this->canDecide($user, $year), 403);

        if ($this->envelopeReceived($year)) {
            throw new DomainException(__('The budget for :year has been received, so its plan is final: only the distribution of the envelope can still change.', ['year' => $year]));
        }
        if ($this->isOpen($year)) {
            throw new DomainException(__('Planning for :year is already open.', ['year' => $year]));
        }
        if (mb_strlen(trim($remark)) < 3) {
            throw new DomainException(__('Say why planning is being reopened.'));
        }

        $this->write($user, $year, self::REOPENED, trim($remark));
    }

    private function write(User $user, int $year, string $state, ?string $remark): void
    {
        $remark = $remark !== null && trim($remark) !== '' ? trim($remark) : null;

        $cycle = BudgetCycle::firstOrNew(['fiscal_year' => $year]);
        $cycle->auditMetadata = array_filter(['by' => $user->id, 'remark' => $remark, 'planning' => $state === self::FINALIZED ? 'finalized' : 'reopened']);
        $cycle->fill(['planning_state' => $state, 'planning_by' => $user->id, 'planning_at' => now(), 'planning_remark' => $remark])->save();
    }
}
