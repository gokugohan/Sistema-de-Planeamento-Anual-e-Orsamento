<?php

namespace Tests;

use App\Models\SystemSetting;
use App\Services\PlanningWindow;
use App\Support\FiscalYear;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // SystemSetting keeps settings in static memory for the life of the PHP process.
        // A real request starts clean; the test runner does not, so reset it per test.
        SystemSetting::flushCache();
        PlanningWindow::forgetTree();
        FiscalYear::pin(null); // a year pinned by one test never leaks into the next
        FiscalYear::forget();
    }
}
