<?php

namespace App\Http\Controllers;

use App\Models\FiscalEnvelope;
use App\Services\PerformanceReport;
use Illuminate\Http\Request;

class PerformanceReportController extends Controller
{
    public function __construct(private PerformanceReport $reports) {}

    /** The compiled monthly or quarterly performance report: on screen, ready to print. */
    public function show(Request $request)
    {
        $year = (int) $request->query('year', FiscalEnvelope::getEffectiveYear());
        abort_if($year < 2020 || $year > 2035, 404);
        $type = $request->query('type') === 'quarter' ? 'quarter' : 'month';
        $default = $year === (int) now()->year ? ($type === 'quarter' ? (int) ceil(now()->month / 3) : (int) now()->month) : ($year < now()->year ? ($type === 'quarter' ? 4 : 12) : 1);
        $period = (int) $request->query('period', $default);

        return view('reports.performance', ['report' => $this->reports->build($year, $type, $period, $request->user())]);
    }
}
