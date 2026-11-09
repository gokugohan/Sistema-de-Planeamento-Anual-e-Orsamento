<?php

namespace App\Services;

use App\Models\Activity;
use App\Support\SpreadsheetKit as Kit;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The final report of the approved plan (ApprovedPlanReport) as an Excel workbook, with the same figures as the page
 * and the PDF: a cover, the plan (complete report only) with one row per activity, and the summaries.
 *
 * Amounts are numbers; the totals of the plan sheet are formulas (SUBTOTAL, so they stay right when the sheet is
 * filtered). Every text from the database is written as text, so a description that starts with "=" is never run
 * as a formula.
 */
class ApprovedPlanWorkbook
{
    public function build(array $report, string $mode): Spreadsheet
    {
        $book = Kit::book($report['reference'].' — '.__('Annual Activity Plan').' '.$report['year'], $report['generated_by'] ?? null, (string) $report['organization']);

        $this->cover($book->getActiveSheet(), $report, $mode);
        if ($report['totals']['count'] > 0) {
            if ($mode === 'complete') {
                $this->plan($book->createSheet(), $report);
            }
            $this->summaries($book->createSheet(), $report);
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function cover(Worksheet $s, array $report, string $mode): void
    {
        $s->setTitle(Kit::sheetTitle(__('Cover')));
        $s->getColumnDimension('A')->setWidth(30);
        $s->getColumnDimension('B')->setWidth(70);

        $unit = $report['unit'] ?? null;
        $row = Kit::heading($s, (string) $report['organization'], __('Annual Activity Plan').' '.$report['year'].($unit ? ' — '.$unit->code.' '.$unit->name : ''), $mode === 'complete' ? __('Complete report') : __('Summary report'));
        $pairs = [
            [__('Document reference'), $report['reference']],
            [__('Fiscal year'), (string) $report['year']],
            ...($unit ? [[__('Unit'), $unit->code.' — '.$unit->name.' ('.__('with the units below it').')']] : []),
            [__('Scope'), __('Activities approved internally (status Approved)')],
            [__('Generated'), $report['generated_at']->format('Y-m-d H:i').' TLT'.($report['generated_by'] ? ' · '.$report['generated_by'] : '')],
        ];
        if ($report['baseline'] ?? null) {
            $pairs[] = [__('Baseline:'), __('the approved plan as it stood on :date, recorded by :by.', ['date' => $report['baseline']['recorded_at']->format('d/m/Y H:i'), 'by' => $report['baseline']['by'] ?? '—']).($report['baseline']['note'] ? ' “'.$report['baseline']['note'].'”' : '')];
        }
        foreach ($pairs as [$label, $value]) {
            Kit::text($s, "A{$row}", $label);
            Kit::text($s, "B{$row}", (string) $value);
            $s->getStyle("A{$row}")->getFont()->getColor()->setRGB('64748B');
            $row++;
        }

        $notes = [];
        if (($report['revising'] ?? 0) > 0) {
            $notes[] = __('Revisions in progress:').' '.__(':n approved activities are being revised. They appear here as last approved, until the revision is approved.', ['n' => $report['revising']]);
        }
        $pending = array_sum($report['pending']);
        if ($pending > 0) {
            $notes[] = __('Plan not complete:').' '.__(':n activities for this year are still in the internal approval workflow and are NOT included:', ['n' => $pending]).' '
                .collect($report['pending'])->map(fn ($n, $status) => __(['Submitted' => 'Prepared', 'Reviewed' => 'Verified', 'Validated' => 'Certified'][$status] ?? $status).' ('.$n.')')->implode(', ').'.';
        }
        foreach ($notes as $note) {
            Kit::note($s, ++$row, 2, $note);
        }

        $row += 2;
        $kpis = [
            [__('Approved activities'), $report['totals']['count'], null],
            [__('Organic units'), $report['by_unit']->sum(fn ($r) => $r['units']->count()), null],
            [__('Indicators'), $report['indicator_count'], null],
            [__('Total planned resources'), $report['totals']['total'], Kit::MONEY],
            ...($unit && ($report['institution_total'] ?? 0) > 0 ? [[__('Share of the plan'), $report['totals']['total'] / $report['institution_total'], Kit::PERCENT]] : []),
        ];
        foreach ($kpis as [$label, $value, $format]) {
            Kit::text($s, "A{$row}", $label);
            $s->setCellValue("B{$row}", $value);
            $s->getStyle("B{$row}")->getFont()->setBold(true);
            $s->getStyle("B{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            if ($format) {
                $s->getStyle("B{$row}")->getNumberFormat()->setFormatCode($format);
            }
            $row++;
        }
        if ($report['totals']['count'] === 0) {
            Kit::text($s, 'A'.($row + 1), __('There are no approved activities for this fiscal year yet.'));
        }
    }

    /** The plan of approved activities: programme and subprogramme bands, then one row per activity. */
    private function plan(Worksheet $s, array $report): void
    {
        $s->setTitle(Kit::sheetTitle(__('Plan of approved activities')));
        $classes = $report['classes'];
        $heads = [__('Code'), __('Activity'), __('Programme'), __('Subprogramme'), __('Unit'), __('Performance indicators'), __('Baseline'), __('Target'), __('Means of verification'), __('FF'), '1T', '2T', '3T', '4T'];
        $firstCat = count($heads) + 1;
        foreach ($classes as $c) {
            $heads[] = $c->code.' '.$c->name;
        }
        $heads[] = __('Total');
        $last = count($heads);
        $totalCol = Coordinate::stringFromColumnIndex($last);
        $catCols = range($firstCat, $last - 1);
        $L = fn (int $i) => Coordinate::stringFromColumnIndex($i);

        foreach ($heads as $i => $h) {
            Kit::text($s, $L($i + 1).'1', $h);
        }
        Kit::band($s, 1, $last, Kit::FILL['head'], true);
        $s->getStyle("A1:{$totalCol}1")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $s->getRowDimension(1)->setRowHeight(30);

        $row = 2;
        $first = 2;
        foreach ($report['tree'] as $p) {
            $pRow = $row++;
            Kit::text($s, "A{$pRow}", (string) $p['program']->oge_code);
            Kit::text($s, "B{$pRow}", __('Programme').' '.$p['program']->oge_code.': '.$p['program']->name);
            Kit::band($s, $pRow, $last, Kit::FILL['prog'], true);
            $start = $row;

            foreach ($p['subprograms'] as $sp) {
                $sRow = $row++;
                Kit::text($s, "A{$sRow}", (string) $sp['subprogram']->code);
                Kit::text($s, "B{$sRow}", __('Subprogramme').' '.$sp['subprogram']->code.': '.$sp['subprogram']->name);
                Kit::band($s, $sRow, $last, Kit::FILL['sub'], true);
                $sStart = $row;
                foreach ($sp['rows'] as $r) {
                    $this->activity($s, $row++, $r, $p['program'], $sp['subprogram'], $classes, $firstCat, $last);
                }
                $this->subtotals($s, $sRow, $sStart, $row - 1, $catCols, $last);
            }
            foreach ($p['loose'] as $r) {
                $this->activity($s, $row++, $r, $p['program'], null, $classes, $firstCat, $last);
            }
            $this->subtotals($s, $pRow, $start, $row - 1, $catCols, $last);
        }

        Kit::text($s, "B{$row}", __('TOTAL'));
        $this->subtotals($s, $row, $first, $row - 1, $catCols, $last);
        Kit::band($s, $row, $last, Kit::FILL['total'], true);

        $s->getStyle($L($firstCat)."2:{$totalCol}{$row}")->getNumberFormat()->setFormatCode(Kit::MONEY);
        $s->getStyle("A1:{$totalCol}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('94A3B8');
        $s->getStyle("A2:{$totalCol}{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $s->getStyle("B2:J{$row}")->getAlignment()->setWrapText(true);
        foreach (['A' => 10, 'B' => 42, 'C' => 9, 'D' => 11, 'E' => 24, 'F' => 40, 'G' => 22, 'H' => 22, 'I' => 20, 'J' => 14] as $col => $w) {
            $s->getColumnDimension($col)->setWidth($w);
        }
        foreach (['K', 'L', 'M', 'N'] as $col) {
            $s->getColumnDimension($col)->setWidth(4);
        }
        foreach (range($firstCat, $last) as $i) {
            $s->getColumnDimension($L($i))->setWidth(15);
        }
        $s->freezePane('C2');
        $s->setAutoFilter("A1:{$totalCol}".($row - 1));
        Kit::landscape($s, "A1:{$totalCol}{$row}");
        $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
    }

    private function activity(Worksheet $s, int $row, array $r, $program, $subprogram, $classes, int $firstCat, int $last): void
    {
        /** @var Activity $a */
        $a = $r['activity'];
        $quarters = $a->execution_quarters ?: [$a->quarter];
        $inds = $a->indicators->values();
        $L = fn (int $i) => Coordinate::stringFromColumnIndex($i);

        $values = [
            (string) $a->code,
            (string) $a->description,
            (string) ($program->oge_code ?? ''),
            (string) ($subprogram->code ?? ''),
            (string) ($a->directorate?->name ?? ''),
            $inds->map(fn ($i, $n) => ($n + 1).') '.$i->name)->implode("\n"),
            $inds->map(fn ($i, $n) => $inds->count() > 1 ? ($n + 1).') '.$i->baseline : (string) $i->baseline)->implode("\n"),
            $inds->map(function ($i, $n) use ($inds) {
                $t = $i->target_text ?: rtrim(rtrim(number_format((float) $i->annual_target, 2), '0'), '.').' '.$i->unit;

                return $inds->count() > 1 ? ($n + 1).') '.$t : $t;
            })->implode("\n"),
            $a->indicators->pluck('means_of_verification')->filter()->unique()->implode('; '),
            $a->lineItems->map(fn ($l) => $l->fundingSource?->name)->filter()->unique()->map(fn ($f) => __($f))->implode(', '),
        ];
        foreach ($values as $i => $v) {
            Kit::text($s, $L($i + 1).$row, $v);
        }
        foreach ([1, 2, 3, 4] as $q) {
            $cell = $L(10 + $q).$row;
            if (in_array($q, array_map('intval', (array) $quarters), true)) {
                Kit::text($s, $cell, '●');
                $s->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(Kit::FILL['quarter']);
                $s->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
        }
        $col = $firstCat;
        foreach ($classes as $c) {
            $v = (float) ($r['by_class'][$c->id] ?? 0);
            if ($v != 0) {
                $s->setCellValue($L($col).$row, $v);
            }
            $col++;
        }
        $s->setCellValue($L($last).$row, '=SUM('.$L($firstCat).$row.':'.$L($last - 1).$row.')');
    }

    /** SUBTOTAL(9, …) of the activity rows below a band: it skips the other subtotals, so nesting adds up once. */
    private function subtotals(Worksheet $s, int $row, int $from, int $to, array $catCols, int $last): void
    {
        if ($to < $from) {
            return;
        }
        foreach ([...$catCols, $last] as $i) {
            $col = Coordinate::stringFromColumnIndex($i);
            $s->setCellValue("{$col}{$row}", "=SUBTOTAL(9,{$col}{$from}:{$col}{$to})");
        }
    }

    private function summaries(Worksheet $s, array $report): void
    {
        $s->setTitle(Kit::sheetTitle(__('Summaries')));
        $classes = $report['classes'];
        $grand = (float) $report['totals']['total'];
        $nCols = $classes->count() + 2;
        $L = fn (int $i) => Coordinate::stringFromColumnIndex($i);
        $row = 1;

        // by economic category
        $row = $this->heading($s, $row, __('Summary by economic category'));
        foreach ([__('Code'), __('Category'), __('Amount'), '%'] as $i => $h) {
            Kit::text($s, $L($i + 1).$row, $h);
        }
        Kit::band($s, $row, 4, Kit::FILL['head'], true);
        $start = ++$row;
        foreach ($classes as $c) {
            $v = (float) ($report['totals']['by_class'][$c->id] ?? 0);
            Kit::text($s, "A{$row}", (string) $c->code);
            Kit::text($s, "B{$row}", (string) $c->name);
            $s->setCellValue("C{$row}", $v);
            $s->setCellValue("D{$row}", $grand > 0 ? $v / $grand : 0);
            $row++;
        }
        Kit::text($s, "B{$row}", __('Total'));
        $s->setCellValue("C{$row}", '=SUM(C'.$start.':C'.($row - 1).')');
        $s->setCellValue("D{$row}", $grand > 0 ? 1 : 0);
        Kit::band($s, $row, 4, Kit::FILL['total'], true);
        $s->getStyle("C{$start}:C{$row}")->getNumberFormat()->setFormatCode(Kit::MONEY);
        $s->getStyle("D{$start}:D{$row}")->getNumberFormat()->setFormatCode(Kit::PERCENT);
        $row += 2;

        // a category table: label, one column per category, total (and the share, for the origins)
        $table = function (string $title, string $firstHead, iterable $items, bool $share = false) use ($s, &$row, $classes, $grand, $L, $nCols, $report) {
            $row = $this->heading($s, $row, $title);
            $heads = [$firstHead, ...$classes->map(fn ($c) => $c->code.' '.$c->name)->all(), __('Total')];
            if ($share) {
                $heads[] = '%';
            }
            foreach ($heads as $i => $h) {
                Kit::text($s, $L($i + 1).$row, $h);
            }
            $width = count($heads);
            Kit::band($s, $row, $width, Kit::FILL['head'], true);
            $s->getStyle("A{$row}:".$L($width).$row)->getAlignment()->setWrapText(true);
            $start = ++$row;
            foreach ($items as $item) {
                Kit::text($s, "A{$row}", $item['label']);
                if ($item['indent'] ?? false) {
                    $s->getStyle("A{$row}")->getAlignment()->setIndent(2);
                }
                $col = 2;
                foreach ($classes as $c) {
                    $s->setCellValue($L($col++).$row, (float) ($item['by_class'][$c->id] ?? 0));
                }
                $s->setCellValue($L($col).$row, (float) $item['total']);
                if ($share) {
                    $s->setCellValue($L($col + 1).$row, $grand > 0 ? $item['total'] / $grand : 0);
                }
                if ($item['fill'] ?? null) {
                    Kit::band($s, $row, $width, $item['fill'], $item['bold'] ?? false);
                }
                $row++;
            }
            // the total of the report (not a sum of the rows: a table may list units inside their directorate)
            Kit::text($s, "A{$row}", __('Total'));
            $col = 2;
            foreach ($classes as $c) {
                $s->setCellValue($L($col++).$row, (float) ($report['totals']['by_class'][$c->id] ?? 0));
            }
            $s->setCellValue($L($col).$row, $grand);
            if ($share) {
                $s->setCellValue($L($col + 1).$row, $grand > 0 ? 1 : 0);
                $s->getStyle($L($col + 1).$start.':'.$L($col + 1).$row)->getNumberFormat()->setFormatCode(Kit::PERCENT);
            }
            Kit::band($s, $row, $width, Kit::FILL['total'], true);
            $s->getStyle("B{$start}:".$L($nCols).$row)->getNumberFormat()->setFormatCode(Kit::MONEY);
            $row += 2;
        };

        $table(__('Summary by programme and economic category'), __('Programme'),
            $report['by_program']->map(fn ($p) => ['label' => $p['code'].' — '.$p['name'].' ('.$p['count'].')'] + $p));

        if ($report['by_origin']->contains(fn ($o) => $o['origin'] !== Activity::ORIGIN_BASELINE)) {
            $table(__('Summary of existing services and new policies'), __('Existing service or new policy'),
                $report['by_origin']->map(fn ($o) => ['label' => $o['label'].' ('.$o['count'].')'] + $o), true);
        }

        $units = [];
        $sections = [
            [__('Ministry services'), $report['by_unit']->where('autonomous', false)],
            [__('Transfers to autonomous bodies (indirect administration)'), $report['by_unit']->where('autonomous', true)],
        ];
        foreach ($sections as [$label, $roots]) {
            if ($roots->isEmpty()) {
                continue;
            }
            foreach ($roots as $root) {
                $units[] = ['label' => $root['code'].' — '.$root['name'].($root['autonomous'] && $root['legal_form'] ? ' ('.$root['legal_form'].')' : ''), 'fill' => Kit::FILL['root'], 'bold' => true] + $root;
                if ($root['units']->count() > 1 || $root['units']->first()['code'] !== $root['code']) {
                    foreach ($root['units'] as $u) {
                        $units[] = ['label' => $u['code'].' — '.$u['name'], 'indent' => true] + $u;
                    }
                }
            }
            if ($report['autonomous']['count'] > 0) {
                $sum = ['by_class' => [], 'total' => $roots->sum('total')];
                foreach ($classes as $c) {
                    $sum['by_class'][$c->id] = $roots->sum(fn ($r) => $r['by_class'][$c->id] ?? 0);
                }
                $units[] = ['label' => __('Subtotal').' — '.$label, 'fill' => Kit::FILL['total'], 'bold' => true] + $sum;
            }
        }
        $table(__('Summary by organic unit and economic category'), __('Organic unit'), $units);

        $s->getColumnDimension('A')->setWidth(55);
        foreach (range(2, $nCols + 1) as $i) {
            $s->getColumnDimension($L($i))->setWidth(16);
        }
        Kit::text($s, "A{$row}", __('Amounts in USD.'));
        $s->getStyle("A{$row}")->getFont()->getColor()->setRGB('64748B');
        Kit::landscape($s, 'A1:'.$L($nCols + 1).$row);
    }

    private function heading(Worksheet $s, int $row, string $title): int
    {
        Kit::text($s, "A{$row}", $title);
        $s->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);

        return $row + 1;
    }
}
