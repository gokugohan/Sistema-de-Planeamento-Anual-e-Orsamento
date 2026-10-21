<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\AuditLog;
use App\Models\BudgetExecution;
use App\Models\Directorate;
use App\Models\IndicatorProgress;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The bulk buttons of "Report execution" leave one audit row per figure, like the single actions do. */
#[Group('office')]
class BulkExecutionAuditTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();

        $unit = Directorate::where('code', 'UDIG')->value('id');
        $this->activity = Activity::where('fiscal_year', 2026)->where('directorate_id', $unit)->where('status', Activity::STATUS_APPROVED)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function auditRows(string $model, array $ids, string $status)
    {
        return AuditLog::where('auditable_type', (new $model)->getMorphClass())->whereIn('auditable_id', $ids)
            ->where('action', 'like', '%.updated')->where('new_values', 'like', '%"status": "'.$status.'"%')->get();
    }

    public function test_verifying_all_spending_audits_every_figure(): void
    {
        $lines = $this->activity->lineItems()->whereHas('adjustedBudget')->get();
        $this->assertGreaterThanOrEqual(1, $lines->count());
        BudgetExecution::whereIn('line_item_id', $lines->pluck('id'))->where('month', 10)->delete();

        $reporter = Volt::actingAs($this->user('director.udig@gdsc.tl'))->test('pages.monitoring.execution-spending')
            ->set('activityId', $this->activity->id)->set('month', 10);
        foreach ($lines as $line) {
            $reporter->set("form.{$line->id}.executed", 50);
        }
        $reporter->call('save', true);
        $entries = BudgetExecution::whereIn('line_item_id', $lines->pluck('id'))->where('month', 10)->get();
        $this->assertCount($lines->count(), $entries);

        Volt::actingAs($this->user('chefe.gdsc@gdsc.tl'))->test('pages.monitoring.execution-spending')
            ->set('activityId', $this->activity->id)->set('month', 10)->call('verifyAll');

        $this->assertSame($lines->count(), BudgetExecution::whereIn('id', $entries->pluck('id'))->where('status', BudgetExecution::VERIFIED)->count());
        $this->assertCount($lines->count(), $this->auditRows(BudgetExecution::class, $entries->pluck('id')->all(), BudgetExecution::VERIFIED), 'one audit row per verified figure');
    }

    public function test_submitting_all_progress_audits_every_figure(): void
    {
        $indicator = $this->activity->indicators()->firstOrFail();
        IndicatorProgress::where('indicator_id', $indicator->id)->where('quarter', 4)->delete();
        $officer = $this->user('director.udig@gdsc.tl');

        $page = Volt::actingAs($officer)->test('pages.monitoring.execution-progress', ['activityId' => $this->activity->id, 'quarter' => 4]);
        $page->call('saveProgress', $indicator->id, 3)->call('submitProgress');

        $progress = IndicatorProgress::where('indicator_id', $indicator->id)->where('quarter', 4)->firstOrFail();
        $this->assertSame('Submitted', $progress->status);
        $this->assertCount(1, $this->auditRows(IndicatorProgress::class, [$progress->id], 'Submitted'));
    }

    public function test_submitting_a_narrative_audits_the_report(): void
    {
        ActivityReport::where('activity_id', $this->activity->id)->delete();
        $page = Volt::actingAs($this->user('director.udig@gdsc.tl'))->test('pages.monitoring.execution-narrative', ['activityId' => $this->activity->id, 'month' => 10]);
        $page->set('form.month.results', 'Work done this month.')->call('save', 'month')->call('submit');

        $report = ActivityReport::where('activity_id', $this->activity->id)->where('period_type', 'month')->firstOrFail();
        $this->assertSame('Submitted', $report->status);
        $this->assertCount(1, $this->auditRows(ActivityReport::class, [$report->id], 'Submitted'));
    }
}
