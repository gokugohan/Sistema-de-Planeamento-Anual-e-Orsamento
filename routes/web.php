<?php

use App\Http\Controllers\ApprovedPlanReportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\PlanBreakdownController;
use App\Models\Activity;
use App\Models\FiscalEnvelope;
use App\Models\SystemSetting;
use App\Services\ActivityCsvImporter;
use Illuminate\Support\Facades\Route;

// Route::view('/', 'welcome');

Route::view('/', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::get('activities', function () {
    return view('activities.index');
})->middleware(['auth', 'verified'])->name('activities.index');

Route::get('activities/create', function () {
    return view('activities.create');
})->middleware(['auth', 'verified'])->name('activities.create');

Route::get('activities/{activity}/edit', function (Activity $activity) {
    return view('activities.edit', ['activity' => $activity]);
})->middleware(['auth', 'verified'])->name('activities.edit');

// The former separate budget and indicator pages are now part of the activity form.
Route::get('activities/{activity}/indicators', fn (Activity $activity) => redirect()->route('activities.edit', $activity))
    ->middleware(['auth', 'verified'])->name('activities.indicators');

Route::get('activities/{activity}/budget', fn (Activity $activity) => redirect()->route('activities.edit', $activity))
    ->middleware(['auth', 'verified'])->name('activities.budget');

Route::get('activities/{activity}', function (Activity $activity) {
    return view('activities.show', ['activity' => $activity]);
})->middleware(['auth', 'verified'])->name('activities.show');

Route::get('budget/envelope/current', function () {
    return view('budget.envelope-current');
})->middleware(['auth', 'verified', 'permission:view_all_paa'])->name('budget.envelope.current');

Route::get('budget/envelope/list', function () {
    return view('budget.envelope-list');
})->middleware(['auth', 'verified', 'permission:view_all_paa'])->name('budget.envelope.list');

Route::get('budget/allocation', function () {
    return view('budget.allocation');
})->middleware(['auth', 'verified', 'permission:view_all_paa'])->name('budget.allocation');

Route::get('monitoring', function () {
    return view('monitoring.index');
})->middleware(['auth', 'verified'])->name('monitoring.index');

Route::get('reports/compile', function () {
    return view('reports.compile');
})->middleware(['auth', 'verified', 'permission:view_reports'])->name('reports.compile');

Route::get('approvals/lists', function () {
    return view('approvals.lists');
})->middleware(['auth', 'verified', 'role:Admin|Director'])->name('approvals.lists');

Route::get('approvals/interim', function () {
    return view('approvals.delegations');
})->middleware(['auth', 'verified', 'role:Admin|Director'])->name('approvals.delegations');

Route::get('approvals', function () {
    return view('approvals.index');
})->middleware(['auth', 'verified', 'role:Admin|Minister|Director|Planning|Finance'])->name('approvals.index');

Route::get('monitoring/report', function () {
    return view('monitoring.report');
})->middleware(['auth', 'verified', 'permission:view_monitoring'])->name('monitoring.report');

// Spending and progress used to be two pages; they are one now.
Route::redirect('monitoring/execution', '/monitoring/report')->name('monitoring.execution');
Route::redirect('monitoring/progress', '/monitoring/report')->name('monitoring.progress');

Route::get('monitoring/approval', function () {
    return view('monitoring.approval');
})->middleware(['auth', 'verified', 'permission:approve_progress_report'])->name('monitoring.approval');

Route::get('planning/paa-approval', function () {
    return view('planning.paa-approval');
})->middleware(['auth', 'verified', 'permission:approve_paa'])->name('planning.paa-approval');

Route::get('planning/director-review', function () {
    return view('planning.director-review');
})->middleware(['auth', 'verified', 'role:Admin|Minister|Director|Planning'])->name('planning.director-review');

// The fiscal ceiling screen was a second way to do what the envelope pages do (and skipped their checks): one place now.
Route::redirect('planning/fiscal', '/budget/envelope/current', 301);

Route::get('planning/budget-cycle', function () {
    return view('planning.budget-cycle');
})->middleware(['auth', 'verified', 'permission:view_all_paa'])->name('planning.budget-cycle');

Route::get('reports/paa', function () {
    return view('reports.paa');
})->middleware(['auth', 'verified', 'permission:view_all_paa'])->name('reports.paa');

// The activities of one programme and category, fetched when that row is expanded.
Route::get('reports/paa/activities', [PlanBreakdownController::class, 'activities'])
    ->middleware(['auth', 'verified', 'permission:view_all_paa'])->name('reports.paa.activities');

// Monthly / quarterly performance report: each reader gets the units they may see
Route::get('reports/performance', [\App\Http\Controllers\PerformanceReportController::class, 'show'])
    ->middleware(['auth', 'verified', 'permission:view_reports'])->name('reports.performance');
Route::get('reports/performance/pdf', [\App\Http\Controllers\PerformanceReportController::class, 'pdf'])
    ->middleware(['auth', 'verified', 'permission:view_reports'])->name('reports.performance.pdf');

Route::middleware(['auth', 'verified', 'permission:view_reports', 'permission:view_all_paa'])->group(function () {
    Route::get('reports/approved-plan', [ApprovedPlanReportController::class, 'show'])->name('reports.approved-plan');
    Route::get('reports/approved-plan/pdf', [ApprovedPlanReportController::class, 'pdf'])->name('reports.approved-plan.pdf');
});

// The old "Entities Registry" was merged into Management > Entities; keep bookmarks working.
Route::redirect('admin/entities', '/management/entities', 301);

Route::group(['prefix' => 'management', 'as' => 'management.', 'middleware' => ['auth', 'verified', 'role:Admin']], function () {
    Route::get('/settings', function () {
        return view('management.settings');
    })->name('settings');

    Route::get('/users', function () {
        return view('management.users');
    })->name('users');

    Route::get('/entities', function () {
        return view('management.entities');
    })->name('entities');

    Route::get('/audit', function () {
        return view('management.audit');
    })->name('audit');

    Route::get('/sample-data', function () {
        return view('management.sample-data');
    })->name('sample-data');

    Route::get('/funding-sources', function () {
        return view('management.funding-sources');
    })->name('funding-sources');

    Route::get('/reporting-windows', function () {
        return view('management.reporting-windows');
    })->name('reporting-windows');

    Route::get('/import', function () {
        return view('management.import');
    })->name('import');

    Route::get('/import/template', function () {
        return response()->streamDownload(
            fn () => print (ActivityCsvImporter::template()),
            'paa-activities-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    })->name('import.template');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

// Monitoring endpoint: database, cache, storage, queue, disk and backup status (no secrets).
Route::get('health', HealthController::class)->middleware('throttle:60,1')->name('health');

// Global fiscal-year context: every page works in the year chosen here.
Route::get('fiscal-year/{year}', function (int $year) {
    if (in_array($year, FiscalEnvelope::availableYears(), true)) {
        session(['fiscal_year' => $year]);
    }

    return redirect()->back();
})->middleware(['auth'])->whereNumber('year')->name('fiscal-year.switch');

Route::get('lang/{locale}', function ($locale) {
    if (in_array($locale, ['en', 'pt', 'tet'])) {
        session(['locale' => $locale]);

        return redirect()->back()->withCookie(cookie()->forever('locale', $locale));
    }

    return redirect()->back();
})->name('lang.switch');

Route::group(['prefix' => 'testing', 'as' => 'testing.', 'middleware' => ['auth', 'verified']], function () {
    Route::get('/', function () {
        if (! SystemSetting::get('enable_paa_testing_mode', false)) {
            abort(404);
        }

        return view('testing.index');
    })->name('index');

    Route::get('/activities', function () {
        if (! SystemSetting::get('enable_paa_testing_mode', false)) {
            abort(404);
        }

        return view('testing.activities');
    })->name('activities');

    Route::get('/activities/create', function () {
        if (! SystemSetting::get('enable_paa_testing_mode', false)) {
            abort(404);
        }

        return view('testing.activities.create');
    })->name('activities.create');

    Route::get('/activities/{activity}', function (Activity $activity) {
        if (! SystemSetting::get('enable_paa_testing_mode', false) || ! $activity->is_test_data) {
            abort(404);
        }

        return view('testing.activities.show', ['activity' => $activity]);
    })->name('activities.show');

    Route::get('/activities/{activity}/indicators', function (Activity $activity) {
        if (! SystemSetting::get('enable_paa_testing_mode', false) || ! $activity->is_test_data) {
            abort(404);
        }

        return view('testing.activities.indicators', ['activity' => $activity]);
    })->name('activities.indicators');

    Route::get('/activities/{activity}/budget', function (Activity $activity) {
        if (! SystemSetting::get('enable_paa_testing_mode', false) || ! $activity->is_test_data) {
            abort(404);
        }

        return view('testing.activities.budget', ['activity' => $activity]);
    })->name('activities.budget');
});

Route::get('notifications', function () {
    return view('notifications.index');
})->middleware(['auth', 'verified'])->name('notifications.index');

require __DIR__.'/auth.php';
