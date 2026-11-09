<?php

namespace App\Http\Controllers;

use App\Services\PlanBreakdown;
use App\Support\ReportScope;
use Illuminate\Http\Request;

class PlanBreakdownController extends Controller
{
    /** Table rows for the activities of one programme and economic category (the report loads them on expand). */
    public function activities(Request $request, PlanBreakdown $breakdown)
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'program' => ['required', 'integer'],
            'class' => ['required', 'integer'],
            'parent' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]+$/', 'max:80'],
        ]);

        // the same part of the plan as the page: one unit for whoever cannot see the whole institution
        $scope = ReportScope::for($request->user(), $request->query('unit'));

        return view('reports.partials.paa-activity-rows', [
            'rows' => $breakdown->forUnits($scope->ids)->activities((int) $data['year'], (int) $data['program'], (int) $data['class']),
            'classRowId' => $data['parent'],
        ]);
    }
}
