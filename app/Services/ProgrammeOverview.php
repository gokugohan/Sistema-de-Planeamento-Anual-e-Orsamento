<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\BudgetExecution;
use App\Models\Program;
use App\Models\Subprogram;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The programmes of a fiscal year with their sub-programmes, and for each how many activities it has, how many are
 * approved, and the amounts planned, in force and executed (verified). Only the activities the user may see are counted:
 * a directorate sees the figures of its own units, an administrator or anyone who sees every plan sees all of them.
 */
class ProgrammeOverview
{
    /**
     * @return array{programmes: array<int, array<string, mixed>>, totals: array<string, float|int>, scoped: bool}
     */
    public function build(User $user, int $year): array
    {
        $visible = app(PlanCycle::class)->scopeFor($user); // null = every unit
        $scoped = $visible !== null;

        $figures = $this->figures($year, $visible);
        $executed = $this->executed($year, $visible);

        $programs = Program::forYear($year)->orderBy('oge_code')->get(['id', 'oge_code', 'name', 'objective']);
        $subprograms = Subprogram::whereIn('program_id', $programs->pluck('id'))->orderBy('code')->get(['id', 'program_id', 'code', 'name'])->groupBy('program_id');

        $rows = [];
        $totals = ['activities' => 0, 'approved_activities' => 0, 'planned' => 0.0, 'in_force' => 0.0, 'executed' => 0.0];
        foreach ($programs as $program) {
            $subs = [];
            foreach ($subprograms->get($program->id, collect()) as $sub) {
                $f = $figures[$program->id][$sub->id] ?? null;
                if ($scoped && ! $f) {
                    continue; // nothing of this sub-programme in the user's units
                }
                $subs[] = $this->row($f, $executed[$program->id][$sub->id] ?? 0.0) + ['id' => $sub->id, 'code' => (string) $sub->code, 'name' => $sub->name];
            }
            $loose = $figures[$program->id][0] ?? null; // activities filed straight under the programme
            if ($loose) {
                $subs[] = $this->row($loose, $executed[$program->id][0] ?? 0.0) + ['id' => null, 'code' => '', 'name' => null];
            }

            $own = $this->row(null, 0.0);
            foreach ($subs as $s) {
                foreach (['activities', 'approved_activities', 'planned', 'in_force', 'executed'] as $k) {
                    $own[$k] += $s[$k];
                }
            }
            if ($scoped && $own['activities'] === 0) {
                continue; // a programme the user has no activity in
            }
            foreach (['activities', 'approved_activities', 'planned', 'in_force', 'executed'] as $k) {
                $totals[$k] += $own[$k];
            }
            $rows[] = $own + ['id' => $program->id, 'code' => (string) $program->oge_code, 'name' => $program->name, 'objective' => $program->objective, 'subs' => $subs];
        }

        return ['programmes' => $rows, 'totals' => $totals, 'scoped' => $scoped];
    }

    /** @return array{activities: int, approved_activities: int, planned: float, in_force: float, executed: float} */
    private function row(?object $f, float $executed): array
    {
        return [
            'activities' => (int) ($f->activities ?? 0), 'approved_activities' => (int) ($f->approved ?? 0),
            'planned' => (float) ($f->planned ?? 0), 'in_force' => (float) ($f->in_force ?? 0), 'executed' => $executed,
        ];
    }

    /** Activities and amounts per programme and sub-programme (0 = none) in one grouped query. @return array<int, array<int, object>> */
    private function figures(int $year, ?array $visible): array
    {
        $rows = DB::table('activities as a')
            ->leftJoin('line_items as li', fn ($j) => $j->on('li.activity_id', '=', 'a.id')->where('li.is_test_data', false))
            ->leftJoin('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')
            ->where('a.fiscal_year', $year)->where('a.is_test_data', false)
            ->when($visible !== null, fn ($q) => $q->whereIn('a.directorate_id', $visible ?: [0]))
            ->groupBy('a.program_id', 'a.subprogram_id')
            ->selectRaw('a.program_id, COALESCE(a.subprogram_id, 0) AS sub_id, COUNT(DISTINCT a.id) AS activities,
                COUNT(DISTINCT CASE WHEN a.status = ? THEN a.id END) AS approved,
                COALESCE(SUM(CASE WHEN li.deleted_at IS NULL THEN li.estimated_amount ELSE 0 END), 0) AS planned,
                COALESCE(SUM(ab.approved_amount), 0) AS in_force', [Activity::STATUS_APPROVED])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->program_id][$r->sub_id] = $r;
        }

        return $out;
    }

    /** Verified payments per programme and sub-programme. @return array<int, array<int, float>> */
    private function executed(int $year, ?array $visible): array
    {
        $rows = DB::table('budget_executions as be')
            ->join('line_items as li', 'li.id', '=', 'be.line_item_id')
            ->join('activities as a', 'a.id', '=', 'li.activity_id')
            ->where('a.fiscal_year', $year)->where('a.is_test_data', false)->where('be.status', BudgetExecution::VERIFIED)
            ->when($visible !== null, fn ($q) => $q->whereIn('a.directorate_id', $visible ?: [0]))
            ->groupBy('a.program_id', 'a.subprogram_id')
            ->selectRaw('a.program_id, COALESCE(a.subprogram_id, 0) AS sub_id, COALESCE(SUM(be.executed), 0) AS executed')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->program_id][$r->sub_id] = (float) $r->executed;
        }

        return $out;
    }
}
