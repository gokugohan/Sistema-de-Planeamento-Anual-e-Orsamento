<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\ImplementationPlanReport;
use App\Services\ImplementationPlanWorkbook;
use App\Support\FiscalYear;
use App\Support\SpreadsheetKit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ImplementationPlanController extends Controller
{
    public function __construct(private ImplementationPlanReport $reports) {}

    /** The implementation plan of a unit in the ministry's form: on screen, ready to print. */
    public function show(Request $request)
    {
        [$report, $units, $programs] = $this->build($request);

        return view('reports.implementation-plan', ['report' => $report, 'units' => $units, 'programs' => $programs, 'pdf' => false]);
    }

    public function pdf(Request $request)
    {
        [$report] = $this->build($request);

        AuditLog::create([
            'action' => 'report.implementation_plan_pdf',
            'user_id' => auth()->id(),
            'description' => "Implementation plan {$report['reference']} exported as PDF",
            'metadata' => ['fiscal_year' => $report['year'], 'unit' => $report['unit']?->code, 'reference' => $report['reference'], 'activities' => $report['activities']],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        // a long plan (every programme of the institution) needs room in a web request
        if (! app()->runningInConsole()) {
            @ini_set('memory_limit', '512M');
            @set_time_limit(180);
        }

        $pdf = Pdf::loadView('reports.implementation-plan', ['report' => $report, 'units' => collect(), 'programs' => collect(), 'pdf' => true])->setPaper('a4', 'landscape');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 26, __('Page').' {PAGE_NUM} / {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.39, 0.45, 0.55]);

        return $pdf->download("{$report['reference']}.pdf");
    }

    /** The same plan as an Excel workbook, in the ministry's form. */
    public function excel(Request $request, ImplementationPlanWorkbook $workbook)
    {
        [$report] = $this->build($request);

        AuditLog::create([
            'action' => 'report.implementation_plan_xlsx',
            'user_id' => auth()->id(),
            'description' => "Implementation plan {$report['reference']} exported as Excel",
            'metadata' => ['fiscal_year' => $report['year'], 'unit' => $report['unit']?->code, 'reference' => $report['reference'], 'activities' => $report['activities']],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        return SpreadsheetKit::download($workbook->build($report), "{$report['reference']}.xlsx");
    }

    /**
     * One unit's plan (reports.implementation-plan.*), or the plan by programme across the units the reader may see
     * (reports.plan-by-program.*): the same form either way.
     *
     * @return array{0: array<string, mixed>, 1: Collection, 2: Collection}
     */
    private function build(Request $request): array
    {
        $year = (int) $request->query('year', FiscalYear::current());
        abort_if($year < 2020 || $year > 2035, 404);
        $approvedOnly = $request->query('status') !== 'all';

        if (str_starts_with((string) $request->route()?->getName(), 'reports.plan-by-program')) {
            $program = $request->query('program') ? (int) $request->query('program') : null;

            return [$this->reports->byProgram($request->user(), $year, $program, $approvedOnly), collect(), $this->reports->programs($request->user(), $year)];
        }

        $unit = $request->query('unit') ? (int) $request->query('unit') : null;

        return [
            $this->reports->build($request->user(), $year, $unit, $approvedOnly),
            $this->reports->units($request->user(), $year),
            collect(),
        ];
    }
}
