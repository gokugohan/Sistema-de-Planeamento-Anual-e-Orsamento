<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\AnnualPlanReport;
use App\Support\FiscalYear;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class AnnualPlanReportController extends Controller
{
    public function __construct(private AnnualPlanReport $reports) {}

    /** The annual report of the plan on screen, ready to print. */
    public function show(Request $request)
    {
        return view('reports.annual-plan', ['report' => $this->build($request), 'pdf' => false]);
    }

    /** The same report as a PDF document (A4 landscape), with its reference on every page. */
    public function pdf(Request $request)
    {
        $report = $this->build($request);

        AuditLog::create([
            'action' => 'report.annual_plan_pdf',
            'user_id' => auth()->id(),
            'description' => "Annual plan report {$report['year']} exported as PDF ({$report['reference']})",
            'metadata' => ['fiscal_year' => $report['year'], 'reference' => $report['reference'], 'planned' => $report['totals']['planned'], 'approved' => $report['totals']['approved']],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        // many activities make a long document: give this web request room (not the console or the tests)
        if (! app()->runningInConsole()) {
            @ini_set('memory_limit', '512M');
            @set_time_limit(180);
        }

        $pdf = Pdf::loadView('reports.annual-plan', ['report' => $report, 'pdf' => true])->setPaper('a4', 'landscape');

        // "Page X / Y" on every page (CSS page counters are not supported by dompdf)
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 26, __('Page').' {PAGE_NUM} / {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.39, 0.45, 0.55]);

        return $pdf->download("{$report['reference']}.pdf");
    }

    private function build(Request $request): array
    {
        $year = (int) $request->query('year', FiscalYear::current());
        abort_if($year < 2020 || $year > 2035, 404);

        return $this->reports->build($year, $request->user());
    }
}
