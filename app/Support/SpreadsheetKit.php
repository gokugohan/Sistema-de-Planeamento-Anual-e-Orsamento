<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What every Excel document of the application shares: the institution's heading with its logo (the same logo as the
 * printed reports), text written as text (never read as a formula), coloured bands, A4 landscape pages with the
 * page number, and the download.
 */
final class SpreadsheetKit
{
    public const MONEY = '#,##0.00';

    public const PERCENT = '0.0%';

    public const FILL = ['head' => 'BDD6EE', 'prog' => 'FFCCCC', 'sub' => 'FFFF00', 'subp' => 'FFFFD9', 'class' => 'DDEBF7', 'total' => 'DDEBF7', 'root' => 'EEECE1', 'quarter' => '439AFF', 'note' => 'FEF3C7'];

    public static function book(string $title, ?string $author, string $organization): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle($title)->setCreator((string) $author)->setCompany($organization);
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);

        return $book;
    }

    /**
     * The heading of a sheet: the logo (when the institution has one), the State, the institution and the title, from row 1.
     * Returns the next free row.
     */
    public static function heading(Worksheet $s, string $organization, string $title, ?string $subtitle = null): int
    {
        $row = 1;
        if ($path = Branding::logoPath()) {
            $logo = new Drawing;
            $logo->setPath($path)->setHeight(48)->setCoordinates('A1')->setOffsetX(4)->setOffsetY(4)->setWorksheet($s);
            $s->getRowDimension(1)->setRowHeight(42);
            $row = 2;
        }
        self::text($s, "A{$row}", 'República Democrática de Timor-Leste');
        $s->getStyle("A{$row}")->getFont()->setSize(8)->getColor()->setRGB('64748B');
        self::text($s, 'A'.($row + 1), $organization);
        $s->getStyle('A'.($row + 1))->getFont()->setBold(true)->setSize(12);
        self::text($s, 'A'.($row + 2), $title);
        $s->getStyle('A'.($row + 2))->getFont()->setBold(true)->setSize(11);
        $next = $row + 3;
        if ($subtitle !== null && $subtitle !== '') {
            self::text($s, "A{$next}", $subtitle);
            $s->getStyle("A{$next}")->getFont()->getColor()->setRGB('64748B');
            $next++;
        }

        return $next + 1;
    }

    /** A text cell: written as text, never read as a number or a formula (a description may start with "="). */
    public static function text(Worksheet $s, string $cell, ?string $value): void
    {
        $s->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
    }

    public static function band(Worksheet $s, int $row, int $width, string $rgb, bool $bold = false): void
    {
        $range = "A{$row}:".Coordinate::stringFromColumnIndex($width).$row;
        $s->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
        $s->getStyle($range)->getFont()->setBold($bold);
    }

    /** A highlighted note across the first columns (a warning, a plan not complete). */
    public static function note(Worksheet $s, int $row, int $width, string $text): void
    {
        $range = "A{$row}:".Coordinate::stringFromColumnIndex($width).$row;
        $s->mergeCells($range);
        self::text($s, "A{$row}", $text);
        $s->getStyle($range)->getAlignment()->setWrapText(true);
        $s->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::FILL['note']);
        $s->getStyle($range)->getFont()->setBold(true)->getColor()->setRGB('78350F');
        $s->getRowDimension($row)->setRowHeight(30);
    }

    public static function landscape(Worksheet $s, string $area, ?int $repeatRow = null): void
    {
        $s->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)->setFitToHeight(0)->setPrintArea($area);
        if ($repeatRow !== null) {
            $s->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($repeatRow, $repeatRow);
        }
        $s->getPageMargins()->setTop(0.4)->setBottom(0.5)->setLeft(0.3)->setRight(0.3);
        $s->getHeaderFooter()->setOddFooter('&L&8&F&R&8'.__('Page').' &P / &N');
    }

    /** A sheet name: at most 31 characters, none of : \ / ? * [ ]. */
    public static function sheetTitle(string $name): string
    {
        return mb_substr(str_replace([':', '\\', '/', '?', '*', '[', ']'], '', $name), 0, 31);
    }

    public static function download(Spreadsheet $book, string $filename): StreamedResponse
    {
        $book->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
