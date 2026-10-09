<?php

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

Route::get('activities/{activity}/indicators', function (App\Models\Activity $activity) {
    return view('activities.indicators', ['activity' => $activity]);
})->middleware(['auth', 'verified'])->name('activities.indicators');

Route::get('activities/{activity}/budget', function (App\Models\Activity $activity) {
    return view('activities.budget', ['activity' => $activity]);
})->middleware(['auth', 'verified'])->name('activities.budget');

Route::get('activities/{activity}', function (App\Models\Activity $activity) {
    return view('activities.show', ['activity' => $activity]);
})->middleware(['auth', 'verified'])->name('activities.show');

Route::get('budget/envelope/current', function () {
    return view('budget.envelope-current');
})->middleware(['auth', 'verified'])->name('budget.envelope.current');

Route::get('budget/envelope/list', function () {
    return view('budget.envelope-list');
})->middleware(['auth', 'verified'])->name('budget.envelope.list');

Route::get('budget/allocation', function () {
    return view('budget.allocation');
})->middleware(['auth', 'verified'])->name('budget.allocation');



Route::get('monitoring', function () {
    return view('monitoring.index');
})->middleware(['auth', 'verified'])->name('monitoring.index');

Route::get('monitoring/approval', function () {
    return view('monitoring.approval');
})->middleware(['auth', 'verified', 'permission:approve_progress_report'])->name('monitoring.approval');

Route::get('planning/paa-approval', function () {
    return view('planning.paa-approval');
})->middleware(['auth', 'verified', 'permission:approve_paa'])->name('planning.paa-approval');


Route::get('planning/director-review', function () {
    return view('planning.director-review');
})->middleware(['auth', 'verified', 'role:Admin|Minister'])->name('planning.director-review');

Route::get('planning/fiscal', function () {
    return view('planning.fiscal');
})->middleware(['auth', 'verified', 'permission:approve_fiscal_envelope'])->name('planning.fiscal');

Route::get('planning/budget-cycle', function () {
    return view('planning.budget-cycle');
})->middleware(['auth', 'verified', 'permission:view_budget'])->name('planning.budget-cycle');

Route::get('reports/paa', function () {
    return view('reports.paa');
})->middleware(['auth', 'verified'])->name('reports.paa');

Route::middleware(['auth', 'verified', 'permission:view_reports'])->group(function () {
    Route::get('reports/approved-plan', [\App\Http\Controllers\ApprovedPlanReportController::class, 'show'])->name('reports.approved-plan');
    Route::get('reports/approved-plan/pdf', [\App\Http\Controllers\ApprovedPlanReportController::class, 'pdf'])->name('reports.approved-plan.pdf');
});

Route::get('admin/entities', function () {
    return view('admin.entities');
})->middleware(['auth', 'verified', 'role:Admin'])->name('admin.entities');

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

    Route::get('/import', function () {
        return view('management.import');
    })->name('import');

    Route::get('/import/template', function () {
        return response()->streamDownload(
            fn () => print(\App\Services\ActivityCsvImporter::template()),
            'paa-activities-template.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    })->name('import.template');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('lang/{locale}', function ($locale) {
    if (in_array($locale, ['en', 'pt', 'tet'])) {
        session(['locale' => $locale]);
        return redirect()->back()->withCookie(cookie()->forever('locale', $locale));
    }
    return redirect()->back();
})->name('lang.switch');

Route::group(['prefix' => 'testing', 'as' => 'testing.', 'middleware' => ['auth', 'verified']], function () {
    Route::get('/', function () {
        if (!\App\Models\SystemSetting::get('enable_paa_testing_mode', false)) {
            abort(404);
        }
        return view('testing.index');
    })->name('index');

    Route::get('/activities', function () {
        if (!\App\Models\SystemSetting::get('enable_paa_testing_mode', false)) {
            abort(404);
        }
        return view('testing.activities');
    })->name('activities');

    Route::get('/activities/create', function () {
        if (!\App\Models\SystemSetting::get('enable_paa_testing_mode', false)) {
            abort(404);
        }
        return view('testing.activities.create');
    })->name('activities.create');

    Route::get('/activities/{activity}', function (App\Models\Activity $activity) {
        if (!\App\Models\SystemSetting::get('enable_paa_testing_mode', false) || !$activity->is_test_data) {
            abort(404);
        }
        return view('testing.activities.show', ['activity' => $activity]);
    })->name('activities.show');

    Route::get('/activities/{activity}/indicators', function (App\Models\Activity $activity) {
        if (!\App\Models\SystemSetting::get('enable_paa_testing_mode', false) || !$activity->is_test_data) {
            abort(404);
        }
        return view('testing.activities.indicators', ['activity' => $activity]);
    })->name('activities.indicators');

    Route::get('/activities/{activity}/budget', function (App\Models\Activity $activity) {
        if (!\App\Models\SystemSetting::get('enable_paa_testing_mode', false) || !$activity->is_test_data) {
            abort(404);
        }
        return view('testing.activities.budget', ['activity' => $activity]);
    })->name('activities.budget');
});

Route::get('notifications', function () {
    return view('notifications.index');
})->middleware(['auth', 'verified'])->name('notifications.index');

require __DIR__ . '/auth.php';
