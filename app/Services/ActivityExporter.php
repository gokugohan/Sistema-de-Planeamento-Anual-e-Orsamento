<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\EconomicClassification;
use App\Models\SystemSetting;
use App\Support\SpreadsheetKit as Kit;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

/**
 * The activity list as an Excel workbook: the shape of a printed annual action plan, one row per activity whatever its
 * stage, with one amount column per economic category and the indicators on the same row, under the institution's
 * heading and logo. It follows the filters of the list.
 */
class ActivityExporter
{
    /** @return \Generator<int, array<int, string|int|float>> header first, then one row per activity (amounts as numbers) */
    public function rows(Builder $activities): \Generator
    {
        $classes = EconomicClassification::orderBy('code')->get(['id', 'code', 'name']);

        yield array_merge(
            [__('Code'), __('Unit'), __('Programme'), __('Activity'), __('Objective'), __('Location'), __('Start'), __('End'), __('Quarters'), __('Priority'), __('Existing service or new policy'), __('Policy priority'), __('Procurement'), __('Gender'), __('Status')],
            $classes->map(fn ($c) => $c->code.' '.$c->name)->all(),
            [__('Total'), __('Indicators')],
        );

        foreach ($activities->with(['directorate:id,code', 'program:id,oge_code', 'lineItems', 'indicators'])->orderBy('directorate_id')->orderBy('id')->get() as $a) {
            $by = $a->lineItems->groupBy('economic_classification_id')->map->sum('estimated_amount');

            yield array_merge(
                [
                    (string) $a->code, $a->directorate->code ?? '', $a->program->oge_code ?? '', (string) $a->description, (string) $a->objective, (string) $a->location,
                    $a->planned_start->format('Y-m-d'), $a->planned_end->format('Y-m-d'),
                    collect($a->execution_quarters ?: [$a->quarter])->map(fn ($q) => "Q$q")->implode(' '),
                    __((string) $a->priority), Activity::originWord($a->origin), (string) $a->policy_ref, $a->procurement_required ? __('Yes') : __('No'), $a->gender_responsive ? __('Yes') : __('No'), Activity::statusWord($a->status),
                ],
                $classes->map(fn ($c) => (float) ($by[$c->id] ?? 0))->all(),
                [(float) $a->lineItems->sum('estimated_amount'), $a->indicators->map(fn ($i) => "{$i->name}: ".(float) $i->annual_target." {$i->unit}")->implode("\n")],
            );
        }
    }

    /**
     * @param  array<int, string>  $filters  what the list was narrowed to, said under the title ("Unit DNTT", …)
     */
    public function workbook(Builder $activities, int $year, array $filters = []): Spreadsheet
    {
        $organization = (string) SystemSetting::getLocalized('org_name', config('app.name'));
        $title = __('Activities').' — '.__('Annual Activity Plan').' '.$year;
        $book = Kit::book($title, auth()->user()?->name, $organization);
        $s = $book->getActiveSheet();
        $s->setTitle(Kit::sheetTitle(__('Activities').' '.$year));

        $subtitle = __('Generated').' '.now('Asia/Dili')->format('Y-m-d H:i').' TLT'.(auth()->user() ? ' · '.auth()->user()->name : '')
            .($filters ? ' · '.__('Filters').': '.implode(', ', $filters) : '');
        $head = Kit::heading($s, $organization, $title, $subtitle);

        $rows = iterator_to_array($this->rows($activities), false);
        $header = array_shift($rows);
        $width = count($header);
        $firstAmount = 16; // after the 15 descriptive columns
        $totalCol = $width - 1; // the total, then the indicators
        $L = fn (int $i) => Coordinate::stringFromColumnIndex($i);

        foreach ($header as $i => $h) {
            Kit::text($s, $L($i + 1).$head, $h);
        }
        Kit::band($s, $head, $width, Kit::FILL['head'], true);
        $s->getStyle("A{$head}:".$L($width).$head)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);

        $row = $head + 1;
        foreach ($rows as $values) {
            foreach ($values as $i => $v) {
                $col = $i + 1;
                if ($col >= $firstAmount && $col <= $totalCol) {
                    $s->setCellValue($L($col).$row, $col === $totalCol ? '=SUM('.$L($firstAmount).$row.':'.$L($totalCol - 1).$row.')' : (float) $v);
                } else {
                    Kit::text($s, $L($col).$row, (string) $v);
                }
            }
            $row++;
        }
        $first = $head + 1;
        $last = $row - 1;

        // the total of what is shown: SUBTOTAL, so a filter in Excel gives the total of the rows left
        Kit::text($s, "D{$row}", __('Total'));
        foreach (range($firstAmount, $totalCol) as $col) {
            $s->setCellValue($L($col).$row, $last >= $first ? '=SUBTOTAL(9,'.$L($col).$first.':'.$L($col).$last.')' : 0);
        }
        Kit::band($s, $row, $width, Kit::FILL['total'], true);

        $s->getStyle($L($firstAmount).$first.':'.$L($totalCol).$row)->getNumberFormat()->setFormatCode(Kit::MONEY);
        $s->getStyle("A{$head}:".$L($width).$row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('94A3B8');
        $s->getStyle("A{$first}:".$L($width).$row)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $s->getStyle("D{$first}:E{$row}")->getAlignment()->setWrapText(true);
        $s->getStyle($L($width).$first.':'.$L($width).$row)->getAlignment()->setWrapText(true);
        foreach (['A' => 11, 'B' => 10, 'C' => 9, 'D' => 45, 'E' => 30, 'F' => 14, 'G' => 11, 'H' => 11, 'I' => 12, 'J' => 9, 'K' => 18, 'L' => 14, 'M' => 10, 'N' => 8, 'O' => 12] as $c => $w) {
            $s->getColumnDimension($c)->setWidth($w);
        }
        foreach (range($firstAmount, $totalCol) as $col) {
            $s->getColumnDimension($L($col))->setWidth(15);
        }
        $s->getColumnDimension($L($width))->setWidth(45);
        $s->freezePane('E'.$first);
        if ($last >= $first) {
            $s->setAutoFilter("A{$head}:".$L($width).$last);
        }
        Kit::landscape($s, 'A1:'.$L($width).$row, $head);

        return $book;
    }
}
