<?php

namespace App\Services;

use App\Models\EconomicClassification;
use Illuminate\Database\Eloquent\Builder;

/**
 * The activity list as a flat CSV that opens in Excel: the shape of a printed annual action plan,
 * with one amount column per economic category and the indicators on the same row.
 */
class ActivityExporter
{
    /** @return \Generator<int, array<int, string|int|float>> header first, then one row per activity */
    public function rows(Builder $activities): \Generator
    {
        $classes = EconomicClassification::orderBy('code')->get(['id', 'code', 'name']);

        yield array_merge(
            [__('Code'), __('Unit'), __('Programme'), __('Activity'), __('Objective'), __('Location'), __('Start'), __('End'), __('Quarters'), __('Priority'), __('Procurement'), __('Gender'), __('Status')],
            $classes->map(fn ($c) => $c->code.' '.$c->name)->all(),
            [__('Total'), __('Indicators')],
        );

        foreach ($activities->with(['directorate:id,code', 'program:id,oge_code', 'lineItems', 'indicators'])->orderBy('directorate_id')->orderBy('id')->get() as $a) {
            $by = $a->lineItems->groupBy('economic_classification_id')->map->sum('estimated_amount');

            yield array_merge(
                [
                    $a->code, $a->directorate->code ?? '', $a->program->oge_code ?? '', $a->description, $a->objective, $a->location,
                    $a->planned_start->format('Y-m-d'), $a->planned_end->format('Y-m-d'),
                    collect($a->execution_quarters ?: [$a->quarter])->map(fn ($q) => "Q$q")->implode(' '),
                    $a->priority, $a->procurement_required ? __('Yes') : __('No'), $a->gender_responsive ? __('Yes') : __('No'), $a->status,
                ],
                $classes->map(fn ($c) => number_format((float) ($by[$c->id] ?? 0), 2, '.', ''))->all(),
                [number_format((float) $a->lineItems->sum('estimated_amount'), 2, '.', ''), $a->indicators->map(fn ($i) => "{$i->name}: ".(float) $i->annual_target." {$i->unit}")->implode(' | ')],
            );
        }
    }

    /** Write to an open stream; the BOM makes Excel read the accents correctly. */
    public function write($handle, Builder $activities): void
    {
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($this->rows($activities) as $row) {
            fputcsv($handle, array_map(fn ($v) => $this->safe($v), $row), ';');
        }
    }

    /** Stop spreadsheet formulas: a cell starting with = + - @ is read as a formula by Excel. */
    private function safe(mixed $v): mixed
    {
        return is_string($v) && $v !== '' && str_contains("=+-@\t\r", $v[0]) && ! is_numeric($v) ? "'".$v : $v;
    }
}
