<?php

namespace App\Services;

use App\Support\SpreadsheetKit as Kit;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The implementation plan of a unit (ImplementationPlanReport) as an Excel workbook, in the ministry's form: each
 * activity with its indicators, the numbered actions, the months they run in and the budget, under the institution's
 * heading and logo. Totals are SUBTOTAL formulas.
 */
class ImplementationPlanWorkbook
{
    private const MONTH_ON = '439AFF';

    private const MONTH_PLANNED = 'C7DDFF';

    /** The plan by programme mixes units: each activity then names its unit. */
    private bool $showUnit = false;

    public function build(array $report): Spreadsheet
    {
        $unit = $report['unit'] ?? null;
        $byProgram = $report['by_program'] ?? false;
        $program = $report['program'] ?? null;
        $title = __('Implementation plan of activities by quarter').' — '.$report['year']
            .($byProgram ? ' · '.__('PAA by program').' · '.($program ? __('Programme').' '.$program->oge_code.': '.$program->name : __('All programs')) : '');
        $this->showUnit = $byProgram;
        $book = Kit::book($report['reference'].' — '.$title, $report['generated_by'] ?? null, (string) $report['organization']);
        $s = $book->getActiveSheet();
        $s->setTitle(Kit::sheetTitle(__('Implementation plan').' '.$report['year']));

        $head = Kit::heading($s, (string) $report['organization'], ($unit ? $unit->code.' — '.$unit->name.' · ' : '').$title,
            $report['reference'].' · '.__('Generated').' '.$report['generated_at']->format('Y-m-d H:i').' · '.$report['generated_by']
            .($report['approved_only'] ? '' : ' · '.__('Include activities not yet approved')));
        if ($warning = app(PlanStatus::class)->reportWarning(auth()->user(), $report['year'], $unit?->id)) {
            Kit::note($s, $head, 16, __('Draft state').': '.$warning);
            $head += 2;
        }

        $L = fn (int $i) => Coordinate::stringFromColumnIndex($i);
        $heads = [__('SDG / PED / Programme / Subprogramme / Activity'), __('Performance indicators and target'), __('Implementation of the activities')];
        foreach (range(1, 12) as $m) {
            $heads[] = Carbon::create($report['year'], $m, 1)->translatedFormat('M');
        }
        $heads[] = __('Budget allocated');
        foreach ($heads as $i => $h) {
            Kit::text($s, $L($i + 1).$head, $h);
        }
        Kit::band($s, $head, 16, Kit::FILL['head'], true);
        $s->getStyle("A{$head}:P{$head}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

        $row = $head + 1;
        $first = $row;
        foreach ($report['tree'] as $p) {
            $pRow = $row++;
            Kit::text($s, "A{$pRow}", __('Programme').' '.$p['program']->oge_code.': '.$p['program']->name);
            Kit::band($s, $pRow, 16, Kit::FILL['prog'], true);
            $pStart = $row;
            foreach ($p['subprograms'] as $sp) {
                $sRow = $row++;
                Kit::text($s, "A{$sRow}", __('Subprogramme').' '.$sp['subprogram']->code.': '.$sp['subprogram']->name);
                Kit::band($s, $sRow, 16, Kit::FILL['sub'], true);
                $sStart = $row;
                foreach ($sp['rows'] as $r) {
                    $row = $this->activity($s, $row, $r);
                }
                $this->subtotal($s, $sRow, $sStart, $row - 1);
            }
            foreach ($p['loose'] as $r) {
                $row = $this->activity($s, $row, $r);
            }
            $this->subtotal($s, $pRow, $pStart, $row - 1);
        }
        if ($row === $first) {
            Kit::text($s, "A{$row}", __('No activities to show for this unit and year.'));
            $row++;
        }
        Kit::text($s, "A{$row}", __('TOTAL'));
        $this->subtotal($s, $row, $first, $row - 1);
        Kit::band($s, $row, 16, Kit::FILL['head'], true);

        $s->getStyle("P{$first}:P{$row}")->getNumberFormat()->setFormatCode(Kit::MONEY);
        $s->getStyle("A{$head}:P{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('94A3B8');
        $s->getStyle("A{$first}:C{$row}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        foreach (['A' => 40, 'B' => 38, 'C' => 40, 'P' => 16] as $c => $w) {
            $s->getColumnDimension($c)->setWidth($w);
        }
        foreach (range(4, 15) as $i) {
            $s->getColumnDimension($L($i))->setWidth(5);
        }
        $s->freezePane('B'.$first);
        Kit::landscape($s, "A1:P{$row}", $head);

        $row += 2;
        Kit::text($s, "A{$row}", __('Prepared by').': '.($report['prepared_by'] ?: '________________'));
        Kit::text($s, "C{$row}", __('Approved by').': '.($report['approved_by'] ?: '________________'));

        return $book;
    }

    /** The rows of one activity: one per action of each indicator (an indicator without actions takes one), then the budget not tied to an indicator. */
    private function activity(Worksheet $s, int $row, array $r): int
    {
        $a = $r['activity'];
        Kit::text($s, "A{$row}", __('Activity').' '.trim($a->code.': '.$a->description).($this->showUnit && $a->directorate ? ' ('.$a->directorate->code.')' : ''));
        $blocks = collect($r['indicators']);
        foreach ($blocks->values() as $n => $b) {
            $ind = $b['indicator'];
            $target = $ind->target_text ?: ((float) $ind->annual_target > 0 ? rtrim(rtrim(number_format((float) $ind->annual_target, 2), '0'), '.').' '.$ind->unit : '');
            Kit::text($s, "B{$row}", ($n + 1).') '.$ind->name.($target !== '' ? ' — '.$target : ''));
            if ($b['budget'] > 0) {
                $s->setCellValue("P{$row}", (float) $b['budget']);
            }
            $planned = $ind->plannedMonths();
            $actions = $ind->actions->values();
            foreach ($actions->isEmpty() ? [null] : $actions as $k => $act) {
                if ($act) {
                    Kit::text($s, "C{$row}", ($k + 1).'. '.$act->text);
                }
                $on = $act ? $act->monthList() : [];
                foreach (range(1, 12) as $m) {
                    $color = in_array($m, $on, true) ? self::MONTH_ON : (! $on && $act && in_array($m, $planned, true) ? self::MONTH_PLANNED : null);
                    if ($color) {
                        $s->getStyle(Coordinate::stringFromColumnIndex(3 + $m).$row)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
                    }
                }
                $row++;
            }
        }
        if ($r['whole'] > 0 || $blocks->isEmpty()) {
            Kit::text($s, "B{$row}", __('Whole activity'));
            $s->getStyle("B{$row}")->getFont()->setItalic(true);
            if ($r['whole'] > 0) {
                $s->setCellValue("P{$row}", (float) $r['whole']);
            }
            $row++;
        }

        return $row;
    }

    private function subtotal(Worksheet $s, int $row, int $from, int $to): void
    {
        $s->setCellValue("P{$row}", $to >= $from ? "=SUBTOTAL(9,P{$from}:P{$to})" : 0);
    }
}
