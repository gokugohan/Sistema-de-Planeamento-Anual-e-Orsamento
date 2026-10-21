<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PlanBaseline;
use App\Services\ApprovedPlanReport;
use App\Services\PlanBaselines;
use App\Support\PlanOptions;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ApprovedPlanReportController extends Controller
{
    public function __construct(private ApprovedPlanReport $reports) {}

    /** Browser view of the document (print-ready, A4). */
    public function show(Request $request)
    {
        $year = $this->year($request);
        $report = $this->reports->build($year, $this->baseline($request, $year));

        return view('reports.approved-plan', [
            'report' => $report, 'pdf' => false, 'mode' => $this->mode($request),
            'baselines' => PlanOptions::current()->baselines ? PlanBaseline::where('fiscal_year', $year)->orderByDesc('recorded_at')->get(['id', 'recorded_at', 'note']) : null,
        ]);
    }

    /** Record the approved plan of the year as a baseline (an optional rule; administrators only). */
    public function recordBaseline(Request $request, PlanBaselines $baselines)
    {
        $year = $this->year($request);
        $request->validate(['note' => 'nullable|string|max:2000']);
        try {
            $b = $baselines->record($request->user(), $year, $request->input('note'));
        } catch (\DomainException $e) {
            return redirect()->route('reports.approved-plan', ['year' => $year])->with('status', $e->getMessage());
        }

        return redirect()->route('reports.approved-plan', ['year' => $year, 'baseline' => $b->id])->with('status', __('Baseline recorded.'));
    }

    /** The baseline asked for, if the rule is on and it belongs to the year. */
    private function baseline(Request $request, int $year): ?PlanBaseline
    {
        if (! PlanOptions::current()->baselines || ! $request->filled('baseline')) {
            return null;
        }

        return PlanBaseline::where('fiscal_year', $year)->findOrFail((int) $request->query('baseline'));
    }

    /** The same report as a PDF: the summary, or the complete report with the plan table. */
    public function pdf(Request $request)
    {
        $year = $this->year($request);
        $report = $this->reports->build($year, $this->baseline($request, $year));
        $mode = $this->mode($request);

        AuditLog::create([
            'action' => 'report.approved_plan_pdf',
            'user_id' => auth()->id(),
            'description' => "Final plan report {$report['year']} ({$mode}) exported as PDF ({$report['reference']})",
            'metadata' => [
                'fiscal_year' => $report['year'],
                'mode' => $mode,
                'reference' => $report['reference'],
                'activities' => $report['totals']['count'],
                'total' => $report['totals']['total'],
                'baseline' => $report['baseline']['id'] ?? null,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);

        $pdf = Pdf::loadView('reports.approved-plan', ['report' => $report, 'pdf' => true, 'mode' => $mode, 'baselines' => null])
            ->setPaper('a4', 'landscape');

        // "Page X / Y" on every page (CSS page counters are not supported by dompdf).
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            $canvas->get_width() - 90,
            $canvas->get_height() - 34,
            __('Page').' {PAGE_NUM} / {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'),
            7,
            [0.39, 0.45, 0.55]
        );

        return $pdf->download("{$report['reference']}-{$mode}.pdf");
    }

    /** 'complete' (the plan table and the summaries) or 'summary' (the summaries only). */
    private function mode(Request $request): string
    {
        return $request->query('mode') === 'summary' ? 'summary' : 'complete';
    }

    private function year(Request $request): int
    {
        $year = (int) $request->query('year', (int) date('Y') + 1);
        abort_if($year < 2020 || $year > 2035, 404);

        return $year;
    }
}
