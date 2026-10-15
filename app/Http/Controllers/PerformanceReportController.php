<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FiscalEnvelope;
use App\Services\PerformanceReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PerformanceReportController extends Controller
{
    public function __construct(private PerformanceReport $reports) {}

    /** The compiled monthly or quarterly performance report: on screen, ready to print. */
    public function show(Request $request)
    {
        return view('reports.performance', ['report' => $this->build($request), 'pdf' => false]);
    }

    /** The same report as a PDF, laid out like the printed one (A4 landscape). */
    public function pdf(Request $request)
    {
        $report = $this->build($request);

        AuditLog::create([
            'action' => 'report.performance_pdf',
            'user_id' => auth()->id(),
            'description' => "Performance report {$report['label']} {$report['year']} exported as PDF ({$report['reference']})",
            'metadata' => ['fiscal_year' => $report['year'], 'type' => $report['type'], 'period' => $report['period'], 'reference' => $report['reference'], 'activities' => $report['totals']['activities']],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        $pdf = Pdf::loadView('reports.performance', ['report' => $report, 'pdf' => true])->setPaper('a4', 'landscape');

        // "Page X / Y" on every page (CSS page counters are not supported by dompdf)
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 26, __('Page').' {PAGE_NUM} / {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('DejaVu Sans'), 7, [0.39, 0.45, 0.55]);

        return $pdf->download("{$report['reference']}.pdf");
    }

    private function build(Request $request): array
    {
        $year = (int) $request->query('year', FiscalEnvelope::getEffectiveYear());
        abort_if($year < 2020 || $year > 2035, 404);
        $type = $request->query('type') === 'quarter' ? 'quarter' : 'month';
        $default = $year === (int) now()->year ? ($type === 'quarter' ? (int) ceil(now()->month / 3) : (int) now()->month) : ($year < now()->year ? ($type === 'quarter' ? 4 : 12) : 1);

        return $this->reports->build($year, $type, (int) $request->query('period', $default), $request->user());
    }
}
