<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PlanBaseline;
use App\Services\ApprovedPlanReport;
use App\Services\ApprovedPlanWorkbook;
use App\Services\PlanBaselines;
use App\Support\PlanOptions;
use App\Support\ReportScope;
use App\Support\SpreadsheetKit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class ApprovedPlanReportController extends Controller
{
    public function __construct(private ApprovedPlanReport $reports) {}

    /** Browser view of the document (print-ready, A4). */
    public function show(Request $request)
    {
        $year = $this->year($request);
        $scope = ReportScope::for($request->user(), $request->query('unit'));
        $report = $this->reports->build($year, $this->baseline($request, $year), $scope);

        return view('reports.approved-plan', [
            'report' => $report, 'pdf' => false, 'mode' => $this->mode($request), 'scope' => $scope,
            'unitChoices' => ReportScope::choices($request->user()),
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
        // a budget decision keeps the plan as it stood as a baseline, so that rule opens them too
        $options = PlanOptions::current();
        if (! ($options->baselines || $options->budgetDecisions) || ! $request->filled('baseline')) {
            return null;
        }

        return PlanBaseline::where('fiscal_year', $year)->findOrFail((int) $request->query('baseline'));
    }

    /** The same report as a PDF: the summary, or the complete report with the plan table. */
    public function pdf(Request $request)
    {
        $year = $this->year($request);
        $scope = ReportScope::for($request->user(), $request->query('unit'));
        $report = $this->reports->build($year, $this->baseline($request, $year), $scope);
        $mode = $this->mode($request);

        $this->logExport($request, $report, $mode, 'pdf', 'PDF');

        $pdf = Pdf::loadView('reports.approved-plan', ['report' => $report, 'pdf' => true, 'mode' => $mode, 'baselines' => null, 'scope' => $scope])
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

    /** The same report as an Excel workbook: cover, the plan (complete report) and the summaries. */
    public function excel(Request $request, ApprovedPlanWorkbook $workbook)
    {
        $year = $this->year($request);
        $scope = ReportScope::for($request->user(), $request->query('unit'));
        $report = $this->reports->build($year, $this->baseline($request, $year), $scope);
        $mode = $this->mode($request);
        $this->logExport($request, $report, $mode, 'xlsx', 'Excel');

        return SpreadsheetKit::download($workbook->build($report, $mode), "{$report['reference']}-{$mode}.xlsx");
    }

    /** Every export is kept in the audit trail with the fingerprint of the plan it came from. */
    private function logExport(Request $request, array $report, string $mode, string $format, string $label): void
    {
        AuditLog::create([
            'action' => "report.approved_plan_{$format}",
            'user_id' => auth()->id(),
            'description' => "Final plan report {$report['year']} ({$mode}) exported as {$label} ({$report['reference']})",
            'metadata' => [
                'fiscal_year' => $report['year'],
                'mode' => $mode,
                'reference' => $report['reference'],
                'fingerprint' => $report['fingerprint'],
                'activities' => $report['totals']['count'],
                'total' => $report['totals']['total'],
                'baseline' => $report['baseline']['id'] ?? null,
                'unit' => $report['unit']?->code,
            ],
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
        ]);
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
