<?php

namespace Tests;

use App\Models\SystemSetting;
use App\Services\PlanningWindow;
use App\Support\FiscalYear;
use App\Support\Modules;
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
        // the add-on modules are on for every test; a test of a switched-off module turns it off itself
        Modules::fake([Modules::EXECUTION => true, Modules::PERFORMANCE => true]);
    }
}
