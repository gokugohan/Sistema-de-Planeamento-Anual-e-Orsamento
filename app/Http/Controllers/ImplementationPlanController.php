<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\ImplementationPlanReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ImplementationPlanController extends Controller
{
    public function __construct(private ImplementationPlanReport $reports) {}

    /** The implementation plan of a unit in the ministry's form: on screen, ready to print. */
    public function show(Request $request)
    {
        [$report, $units] = $this->build($request);

        return view('reports.implementation-plan', ['report' => $report, 'units' => $units, 'pdf' => false]);
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

        $pdf = Pdf::loadView('reports.implementation-plan', ['report' => $report, 'units' => collect(), 'pdf' => true])->setPaper('a4', 'landscape');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 26, __('Page').' {PAGE_NUM} / {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.39, 0.45, 0.55]);

        return $pdf->download("{$report['reference']}.pdf");
    }

    /** @return array{0: array<string, mixed>, 1: \Illuminate\Support\Collection} */
    private function build(Request $request): array
    {
        $year = (int) $request->query('year', \App\Support\FiscalYear::current());
        abort_if($year < 2020 || $year > 2035, 404);
        $unit = $request->query('unit') ? (int) $request->query('unit') : null;

        return [
            $this->reports->build($request->user(), $year, $unit, $request->query('status') !== 'all'),
            $this->reports->units($request->user(), $year),
        ];
    }
}
