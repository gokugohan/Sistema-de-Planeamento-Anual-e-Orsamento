<?php

namespace App\Services;

use App\Support\SpreadsheetKit as Kit;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The monthly or quarterly performance report (PerformanceReport) as an Excel workbook: programme and subprogramme
 * bands, then one row per activity with its indicators (baseline, target, achieved in the quarter and so far), the
 * approved amount, what was executed in the period and its share, the results, constraints and rating. Verified figures
 * only, like the report. Totals are SUBTOTAL formulas, so a filter in Excel gives the total of the rows left.
 */
class PerformanceWorkbook
{
    private const COLS = 15; // A … O

    public function build(array $report): Spreadsheet
    {
        $periodText = $report['type'] === 'quarter' ? $report['period'].'º '.__('Quarter').' '.$report['year'] : $report['label'].' '.$report['year'];
        $title = ($report['type'] === 'quarter' ? __('Quarterly performance report') : __('Monthly performance report')).' — '.$periodText;
        $unit = $report['unit'] ?? null;

        $book = Kit::book($report['reference'].' — '.$title, $report['generated_by'] ?? null, (string) $report['organization']);
        $s = $book->getActiveSheet();
        $s->setTitle(Kit::sheetTitle($report['type'] === 'quarter' ? 'Q'.$report['period'].' '.$report['year'] : $report['label'].' '.$report['year']));

        $head = Kit::heading($s, (string) $report['organization'], ($unit ? $unit->code.' — '.$unit->name.' · ' : '').$title,
            $report['reference'].' · '.__('Verified figures and reports only').' · '.__('Generated').' '.$report['generated_at']->format('Y-m-d H:i').' · '.$report['generated_by']);
        if ($warning = app(PlanStatus::class)->reportWarning(auth()->user(), $report['year'], $unit?->id)) {
            Kit::note($s, $head, self::COLS, __('Draft state').': '.$warning);
            $head += 2;
        }

        $heads = [
            __('SDG / PED / Programme / Subprogramme / Activity'), __('Unit'), __('Performance indicators'), __('Baseline'), __('Target'),
            __('Achieved in Q:q', ['q' => $report['quarter']]), __('so far'), __('Means of verification'), __('FF'),
            __('Total'), __('Execution :p', ['p' => $periodText]).' ($)', '%',
            __('Results achieved').' — '.$periodText, __('Implementation constraints'), __('Rating'),
        ];
        foreach ($heads as $i => $h) {
            Kit::text($s, chr(65 + $i).$head, $h);
        }
        Kit::band($s, $head, self::COLS, Kit::FILL['head'], true);
        $s->getStyle("A{$head}:O{$head}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $s->getRowDimension($head)->setRowHeight(40);

        $row = $head + 1;
        $first = $row;
        foreach ($report['tree'] as $p) {
            $pRow = $row++;
            Kit::text($s, "A{$pRow}", __('Programme').' '.$p['program']->oge_code.': '.$p['program']->name);
            Kit::band($s, $pRow, self::COLS, Kit::FILL['prog'], true);
            $pStart = $row;
            foreach ($p['subprograms'] as $sp) {
                $sRow = $row++;
                Kit::text($s, "A{$sRow}", __('Subprogramme').' '.$sp['subprogram']->code.': '.$sp['subprogram']->name);
                Kit::band($s, $sRow, self::COLS, Kit::FILL['sub'], true);
                $sStart = $row;
                foreach ($sp['rows'] as $r) {
                    $this->activity($s, $row++, $r);
                }
                $this->totals($s, $sRow, $sStart, $row - 1);
            }
            foreach ($p['loose'] as $r) {
                $this->activity($s, $row++, $r);
            }
            $this->totals($s, $pRow, $pStart, $row - 1);
        }
        if ($row === $first) {
            Kit::text($s, "A{$row}", __('No approved activities with a distributed budget to report on.'));
            $row++;
        }
        Kit::text($s, "A{$row}", __('TOTAL EXECUTION'));
        $this->totals($s, $row, $first, $row - 1);
        Kit::band($s, $row, self::COLS, Kit::FILL['total'], true);

        $s->getStyle("J{$first}:K{$row}")->getNumberFormat()->setFormatCode(Kit::MONEY);
        $s->getStyle("L{$first}:L{$row}")->getNumberFormat()->setFormatCode(Kit::PERCENT);
        $s->getStyle("A{$head}:O{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('94A3B8');
        $s->getStyle("A{$first}:O{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        foreach (['A', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'M', 'N', 'O'] as $c) {
            $s->getStyle("{$c}{$first}:{$c}{$row}")->getAlignment()->setWrapText(true);
        }
        foreach (['A' => 40, 'B' => 12, 'C' => 38, 'D' => 18, 'E' => 18, 'F' => 12, 'G' => 12, 'H' => 18, 'I' => 14, 'J' => 15, 'K' => 15, 'L' => 8, 'M' => 40, 'N' => 40, 'O' => 12] as $c => $w) {
            $s->getColumnDimension($c)->setWidth($w);
        }
        $s->freezePane('B'.$first);
        if ($row - 1 >= $first) {
            $s->setAutoFilter("A{$head}:O".($row - 1));
        }
        Kit::landscape($s, "A1:O{$row}", $head);

        $row += 2;
        foreach ([__('Prepared by'), __('Verified by'), __('Approved by')] as $i => $label) {
            Kit::text($s, ['A', 'E', 'M'][$i].$row, $label.': ________________');
        }

        return $book;
    }

    private function activity(Worksheet $s, int $row, array $r): void
    {
        $a = $r['activity'];
        $inds = collect($r['indicators'])->values();
        $lines = fn (callable $f) => $inds->map(fn ($i, $n) => ($inds->count() > 1 ? ($n + 1).') ' : '').$f($i))->implode("\n");

        Kit::text($s, "A{$row}", trim($a->code.' '.$a->description));
        Kit::text($s, "B{$row}", (string) ($a->directorate->code ?? ''));
        Kit::text($s, "C{$row}", $lines(fn ($i) => $i['name'].($i['planned'] ? "\n   ".__('Planned').': '.$i['planned'] : '')));
        Kit::text($s, "D{$row}", $lines(fn ($i) => (string) $i['baseline']));
        Kit::text($s, "E{$row}", $lines(fn ($i) => (string) $i['target']));
        Kit::text($s, "F{$row}", $lines(fn ($i) => $i['in_quarter'] !== null ? number_format((float) $i['in_quarter'], 2).' '.$i['unit'] : '—'));
        Kit::text($s, "G{$row}", $lines(fn ($i) => number_format((float) $i['standing'], 2).' '.$i['unit']));
        Kit::text($s, "H{$row}", (string) $r['means']);
        Kit::text($s, "I{$row}", implode(', ', $r['funding']));
        $s->setCellValue("J{$row}", (float) $r['approved']);
        $s->setCellValue("K{$row}", (float) $r['executed']);
        $s->setCellValue("L{$row}", "=IF(J{$row}=0,0,K{$row}/J{$row})");
        Kit::text($s, "M{$row}", (string) $r['results']);
        Kit::text($s, "N{$row}", trim(implode("\n", array_filter([
            (string) $r['constraints'],
            $r['reasons_not_met'] ? __('Why targets were not met').': '.$r['reasons_not_met'] : null,
            $r['corrective_measures'] ? __('Corrective measures').': '.$r['corrective_measures'] : null,
        ]))));
        Kit::text($s, "O{$row}", $r['rating'] ? __($r['rating']) : '');
    }

    /** Approved and executed of the rows below a band (SUBTOTAL skips the other subtotals), and their share. */
    private function totals(Worksheet $s, int $row, int $from, int $to): void
    {
        foreach (['J', 'K'] as $c) {
            $s->setCellValue("{$c}{$row}", $to >= $from ? "=SUBTOTAL(9,{$c}{$from}:{$c}{$to})" : 0);
        }
        $s->setCellValue("L{$row}", "=IF(J{$row}=0,0,K{$row}/J{$row})");
    }
}
