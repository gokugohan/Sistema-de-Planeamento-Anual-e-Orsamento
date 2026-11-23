<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityVersion;
use App\Models\EconomicClassification;
use App\Models\FundingSource;
use App\Models\Program;
use App\Support\PlanOptions;
use Illuminate\Support\Facades\DB;

/**
 * The rules on budget changes (alterações orçamentais), checked on a revision of an approved activity (an optional
 * rule, PlanOptions::budgetChangeChecks). Decreto-Lei n.º 42/2025, art. 35:
 *   35.1  moving money between programmes, subprogrammes, activities or categories is a budget change;
 *   35.2  money may not leave "salaries and wages" (the regular pay) or "development capital" for another category;
 *   35.3  a move between programmes, or between categories of one programme, is at most 20 % of the appropriation
 *         it comes from, except between the programs of the Infrastructure Fund (Decreto-Lei n.º 26/2026, in force
 *         from 1 January 2026);
 *   35.9  every change says what it does to the targets of the annual plan.
 * The year's execution decree may also forbid increasing some lines (for 2026, Decreto-Lei n.º 41/2025, art. 4.3:
 * travel, accommodation, per diem and training); the institution lists those account codes in the optional rules.
 *
 * These are warnings, never a block: the binding check is the Ministry of Finance's, on the official appropriation in
 * its financial system. Here the appropriation is stood in for by the institution's plan in force for the programme
 * and category, which is what the plan knows of it.
 */
class BudgetChangeRules
{
    /** The categories money may not leave (art. 35.2), by code. */
    public const PROTECTED = ['10', '50'];

    /** The largest share of an appropriation a change may move (art. 35.3). */
    public const LIMIT = 0.20;

    public static function on(): bool
    {
        return PlanOptions::current()->budgetChangeChecks;
    }

    /**
     * What a revision in progress does to the money of the version in force, and what the rules say about it. Null when
     * the rule is off or the activity is not being revised.
     *
     * @return array{moves: array<int, array>, program: ?array{from: string, to: string}, moved: float, moves_money: bool, warnings: array<int, string>}|null
     */
    public function check(Activity $activity): ?array
    {
        if (! self::on() || ! ActivityVersions::revising($activity)) {
            return null;
        }
        $last = ActivityVersion::where('activity_id', $activity->id)->orderByDesc('version')->first();
        if (! $last) {
            return null;
        }
        $activity->loadMissing('lineItems');
        $classes = EconomicClassification::orderBy('code')->get(['id', 'code', 'name'])->keyBy('id');

        $from = [];
        $fromCodes = [];
        foreach ($last->snapshot['lines'] ?? [] as $l) {
            $from[(int) $l['economic_classification_id']] = ($from[(int) $l['economic_classification_id']] ?? 0) + (float) $l['estimated_amount'];
            $fromCodes[(string) ($l['account_code'] ?? '')] = ($fromCodes[(string) ($l['account_code'] ?? '')] ?? 0) + (float) $l['estimated_amount'];
        }
        $to = [];
        $toCodes = [];
        foreach ($activity->lineItems as $l) {
            $to[(int) $l->economic_classification_id] = ($to[(int) $l->economic_classification_id] ?? 0) + (float) $l->estimated_amount;
            $toCodes[(string) $l->account_code] = ($toCodes[(string) $l->account_code] ?? 0) + (float) $l->estimated_amount;
        }

        $oldProgram = (int) ($last->snapshot['activity']['program_id'] ?? $activity->program_id);
        $programChanged = $oldProgram && (int) $activity->program_id !== $oldProgram;

        // what leaves each category: everything, when the activity moves to another programme; otherwise the part of
        // each reduction that another category of the activity takes up
        $decrease = [];
        foreach ($from as $id => $amount) {
            $decrease[$id] = $programChanged ? $amount : max(0.0, $amount - ($to[$id] ?? 0));
        }
        $increase = 0.0;
        foreach ($to as $id => $amount) {
            $increase += max(0.0, $amount - ($from[$id] ?? 0));
        }
        $totalDecrease = array_sum($decrease);
        $factor = $programChanged ? 1.0 : ($totalDecrease > 0 ? min(1.0, $increase / $totalDecrease) : 0.0);

        $moves = [];
        $warnings = [];
        $base = $this->appropriations((int) $activity->fiscal_year, $oldProgram);
        // money of the Infrastructure Fund, before and after: no 20 % limit between its programs
        $fi = FundingSource::infrastructureFund();
        $sources = collect($last->snapshot['lines'] ?? [])->pluck('funding_source_id')->merge($activity->lineItems->pluck('funding_source_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique();
        $infrastructureFund = $fi !== [] && $sources->isNotEmpty() && $sources->every(fn ($id) => in_array($id, $fi, true));
        foreach ($classes as $id => $c) {
            $f = (float) ($from[$id] ?? 0);
            $t = (float) ($to[$id] ?? 0);
            $moved = round(($decrease[$id] ?? 0) * $factor, 2);
            if (abs($f - $t) < 0.005 && $moved < 0.005) {
                continue;
            }
            $moves[] = ['code' => $c->code, 'name' => $c->name, 'from' => $f, 'to' => $t, 'moved' => $moved];
            if ($moved < 0.5) {
                continue;
            }
            if (in_array($c->code, self::PROTECTED, true)) {
                $warnings[] = __('$ :amount leaves :category for another use, which a budget change may not do (Decreto-Lei n.º 42/2025, art. 35.2).', ['amount' => number_format($moved, 0), 'category' => $c->code.' '.$c->name]);
            }
            $appropriation = (float) ($base[$id] ?? 0);
            if ($appropriation > 0 && $moved > self::LIMIT * $appropriation + 0.005 && ! $infrastructureFund) {
                $warnings[] = __('$ :amount moves out of :category, :pct % of what the plan in force has in it for the program: above the 20 % limit (Decreto-Lei n.º 42/2025, art. 35.3).', [
                    'amount' => number_format($moved, 0), 'category' => $c->code.' '.$c->name, 'pct' => number_format(100 * $moved / $appropriation, 1),
                ]);
            }
        }

        foreach (PlanOptions::noIncreaseCodesFor((int) $activity->fiscal_year) as $code) {
            $sum = fn (array $byCode) => array_sum(array_filter($byCode, fn ($v, $k) => str_starts_with((string) $k, $code), ARRAY_FILTER_USE_BOTH));
            $up = $sum($toCodes) - $sum($fromCodes);
            if ($up >= 0.5) {
                $warnings[] = __('Account :code goes up by $ :amount, which this year\'s budget execution rules do not allow.', ['code' => $code, 'amount' => number_format($up, 0)]);
            }
        }

        $program = null;
        if ($programChanged) {
            $names = Program::whereIn('id', [$oldProgram, (int) $activity->program_id])->get(['id', 'oge_code', 'name'])->keyBy('id');
            $label = fn ($id) => $names->has($id) ? trim($names[$id]->oge_code.' '.$names[$id]->name) : '—';
            $program = ['from' => $label($oldProgram), 'to' => $label((int) $activity->program_id)];
        }
        $movedTotal = round(array_sum(array_column($moves, 'moved')), 2);

        return [
            'moves' => $moves,
            'program' => $program,
            'moved' => $movedTotal,
            'moves_money' => $movedTotal >= 0.5,
            'warnings' => $warnings,
        ];
    }

    /** Does this revision need its effect on the targets stated before it is submitted (art. 35.9)? */
    public function needsEffect(Activity $activity): bool
    {
        return ($this->check($activity)['moves_money'] ?? false) && blank($activity->revision_effect);
    }

    /** The institution's plan in force for one programme, by category: what stands in for the appropriation. @return array<int, float> */
    private function appropriations(int $year, int $programId): array
    {
        return DB::table('activities as a')
            ->join('line_items as li', 'li.activity_id', '=', 'a.id')
            ->join('adjusted_budgets as ab', 'ab.line_item_id', '=', 'li.id')
            ->where('a.fiscal_year', $year)->where('a.program_id', $programId)->where('a.is_test_data', false)
            ->groupBy('li.economic_classification_id')
            ->selectRaw('li.economic_classification_id AS id, SUM(ab.approved_amount) AS amount')
            ->pluck('amount', 'id')->map(fn ($v) => (float) $v)->all();
    }
}
