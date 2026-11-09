<?php

namespace App\Services;

use App\Support\SpreadsheetKit as Kit;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * The annual report of the plan (AnnualPlanReport) as an Excel workbook: every activity of the year whatever its stage,
 * planned next to approved. The same two tables as the page and the PDF, by unit and by programme (subprogramme,
 * economic category, activity), with the institution's heading and logo on each sheet.
 */
class AnnualPlanWorkbook
{
    public function build(array $report): Spreadsheet
    {
        $title = __('Annual Activity Plan Report (PAA)').' — '.$report['year'].(($report['unit'] ?? null) ? ' — '.$report['unit']->code.' '.$report['unit']->name : '');
        $book = Kit::book($report['reference'].' — '.$title, $report['generated_by'] ?? null, (string) $report['organization']);

        $this->units($book->getActiveSheet(), $report, $title);
        $this->programmes($book->createSheet(), $report, $title);

        return $book;
    }

    /** The heading, the draft warning and who made the document; returns the row of the table header. */
    private function top(Worksheet $s, array $report, string $title, int $width): int
    {
        $row = Kit::heading($s, (string) $report['organization'], $title,
            $report['reference'].' · '.__('Generated').' '.$report['generated_at']->format('Y-m-d H:i').' TLT · '.$report['generated_by']);
        if ($report['warning'] ?? null) {
            Kit::note($s, $row, $width, __('Draft state').': '.$report['warning']);
            $row += 2;
        }

        return $row;
    }

    private function header(Worksheet $s, int $row, array $heads): void
    {
        foreach ($heads as $i => $h) {
            Kit::text($s, chr(65 + $i).$row, $h);
        }
        Kit::band($s, $row, count($heads), Kit::FILL['head'], true);
        $s->getStyle("A{$row}:".chr(64 + count($heads)).$row)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    }

    private function units(Worksheet $s, array $report, string $title): void
    {
        $s->setTitle(Kit::sheetTitle(__('Planned and approved, by unit')));
        $head = $this->top($s, $report, $title, 4);
        Kit::text($s, 'A'.($head - 1), '1. '.__('Planned and approved, by unit'));
        $s->getStyle('A'.($head - 1))->getFont()->setBold(true);
        $this->header($s, $head, [__('Unit / Directorate Structure'), __('Planned resources ($)'), __('Approved plan ($)'), __('Utilization (%)')]);

        $row = $head + 1;
        foreach ($report['units'] as $u) {
            Kit::text($s, "A{$row}", $u['code'].' — '.$u['name']);
            $s->getStyle("A{$row}")->getAlignment()->setIndent($u['depth'] * 2);
            $s->setCellValue("B{$row}", (float) $u['planned']);
            $s->setCellValue("C{$row}", (float) $u['approved']);
            $s->setCellValue("D{$row}", "=IF(B{$row}=0,0,C{$row}/B{$row})");
            if ($u['depth'] === 0) {
                Kit::band($s, $row, 4, Kit::FILL['root'], true);
            }
            $row++;
        }
        // the institution's totals (the rows roll up the structure, so they are not added again)
        Kit::text($s, "A{$row}", ($report['unit'] ?? null) ? __('Total') : __('Consolidated Institutional Totals'));
        $s->setCellValue("B{$row}", (float) $report['totals']['planned']);
        $s->setCellValue("C{$row}", (float) $report['totals']['approved']);
        $s->setCellValue("D{$row}", "=IF(B{$row}=0,0,C{$row}/B{$row})");
        Kit::band($s, $row, 4, Kit::FILL['head'], true);

        $s->getStyle('B'.($head + 1).":C{$row}")->getNumberFormat()->setFormatCode(Kit::MONEY);
        $s->getStyle('D'.($head + 1).":D{$row}")->getNumberFormat()->setFormatCode(Kit::PERCENT);
        $s->getStyle("A{$head}:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('94A3B8');
        $s->getColumnDimension('A')->setWidth(70);
        foreach (['B', 'C', 'D'] as $c) {
            $s->getColumnDimension($c)->setWidth(20);
        }
        $s->freezePane('A'.($head + 1));
        Kit::landscape($s, "A1:D{$row}", $head);
    }

    private function programmes(Worksheet $s, array $report, string $title): void
    {
        $s->setTitle(Kit::sheetTitle(__('Detailed Programmatic Breakdown')));
        $head = $this->top($s, $report, $title, 7);
        Kit::text($s, 'A'.($head - 1), '2. '.__('Detailed Programmatic Breakdown'));
        $s->getStyle('A'.($head - 1))->getFont()->setBold(true);
        $this->header($s, $head, [__('Hierarchical Breakdown'), __('Unit'), __('Priority'), __('Status / Indicators'), __('Planned ($)'), __('Approved ($)'), __('Difference ($)')]);

        $row = $head + 1;
        $line = function (string $label, string $kind, float $planned, float $approved, ?string $fill, int $indent = 0, array $extra = []) use ($s, &$row) {
            Kit::text($s, "A{$row}", $label);
            $s->getStyle("A{$row}")->getAlignment()->setIndent($indent);
            Kit::text($s, "B{$row}", $extra['unit'] ?? '');
            Kit::text($s, "C{$row}", $extra['priority'] ?? '');
            Kit::text($s, "D{$row}", $kind);
            $s->setCellValue("E{$row}", $planned);
            $s->setCellValue("F{$row}", $approved);
            $s->setCellValue("G{$row}", "=E{$row}-F{$row}");
            if ($fill) {
                Kit::band($s, $row, 7, $fill, true);
            }
            $row++;
        };

        foreach ($report['programmes'] as $p) {
            $program = $p['program'];
            $line($program->oge_code.' — '.$program->name, __('Program Total'), $p['planned'], $p['approved'], Kit::FILL['prog']);
            foreach ($report['subprogrammes'][$program->id] ?? [] as $sp) {
                $line($sp['code'].' · '.$sp['name'], __('Subprogram Total'), $sp['planned'], $sp['approved'], Kit::FILL['subp'], 1);
            }
            foreach ($p['classes'] as $c) {
                $line($c['classification']->code.' '.$c['classification']->name, __('Category Total'), $c['planned'], $c['approved'], Kit::FILL['class'], 1);
                foreach ($c['activities'] as $a) {
                    $indicators = collect($a['indicators'])->map(fn ($i) => $i->name.': '.number_format((float) $i->annual_target, 0).' '.$i->unit)->implode("\n");
                    $line((string) $a['activity']->description, $indicators, $a['planned'], $a['approved'], null, 2,
                        ['unit' => $a['activity']->directorate->code ?? '', 'priority' => __((string) $a['activity']->priority)]);
                }
            }
        }
        if ($row === $head + 1) {
            Kit::text($s, "A{$row}", __('Nothing to report yet'));
            $row++;
        }
        $last = $row - 1;

        $s->getStyle('E'.($head + 1).":G{$last}")->getNumberFormat()->setFormatCode(Kit::MONEY);
        $s->getStyle("A{$head}:G{$last}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('94A3B8');
        $s->getStyle('A'.($head + 1).":D{$last}")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        foreach (['A' => 60, 'B' => 12, 'C' => 10, 'D' => 40, 'E' => 18, 'F' => 18, 'G' => 18] as $c => $w) {
            $s->getColumnDimension($c)->setWidth($w);
        }
        $s->freezePane('A'.($head + 1));
        $s->setAutoFilter("A{$head}:G{$last}");
        Kit::landscape($s, "A1:G{$last}", $head);
    }
}
