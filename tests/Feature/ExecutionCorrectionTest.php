<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BudgetExecution;
use App\Models\IndicatorProgress;
use App\Models\LineItem;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Verified execution figures: someone else verifies them, and only the final approver or an administrator
 * can reopen one for correction, with a remark that is kept in the audit trail.
 */
#[Group('office')]
class ExecutionCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
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

    private function finalApprover(): User
    {
        return $this->user('chefe.gdsc@gdsc.tl'); // the final approver of the office
    }

    private function unitDirector(): User
    {
        return $this->user('director.udig@gdsc.tl');
    }

    // ── money

    private function verifiedLine(): array
    {
        $entry = BudgetExecution::where('month', 10)->where('status', BudgetExecution::VERIFIED)->firstOrFail();
        $line = LineItem::findOrFail($entry->line_item_id);

        return [$entry, $line->activity_id, $line->id];
    }

    private function moneyPage(User $user, int $activityId)
    {
        return Volt::actingAs($user)->test('pages.monitoring.execution-spending')->set('activityId', $activityId)->set('month', 10);
    }

    public function test_the_final_approver_reopens_a_verified_line_with_a_remark_and_it_is_recorded(): void
    {
        [$entry, $activityId, $lineId] = $this->verifiedLine();

        $this->moneyPage($this->finalApprover(), $activityId)->set('reopenId', $lineId)->set('remark', 'The payment was entered twice.')->call('reopen');

        $entry->refresh();
        $this->assertSame(BudgetExecution::DRAFT, $entry->status);
        $this->assertNull($entry->verified_by);
        $this->assertSame('The payment was entered twice.', $entry->notes);
        $log = AuditLog::where('action', 'budgetexecution.updated')->latest('id')->first();
        $this->assertSame('The payment was entered twice.', $log->metadata['remark']);
        $this->assertSame('reopened', $log->metadata['correction']);
    }

    public function test_a_remark_is_required_and_a_unit_director_cannot_reopen(): void
    {
        [$entry, $activityId, $lineId] = $this->verifiedLine();

        $this->moneyPage($this->finalApprover(), $activityId)->set('reopenId', $lineId)->set('remark', ' ')->call('reopen');
        $this->assertSame(BudgetExecution::VERIFIED, $entry->fresh()->status, 'no remark, no change');

        $this->moneyPage($this->unitDirector(), $activityId)->set('reopenId', $lineId)->set('remark', 'I would like to change it')->call('reopen')->assertForbidden();
        $this->assertSame(BudgetExecution::VERIFIED, $entry->fresh()->status);
    }

    // ── physical progress

    private function submittedProgress(User $by): IndicatorProgress
    {
        $p = IndicatorProgress::where('quarter', 4)->where('status', 'Submitted')->firstOrFail();
        $p->update(['submitted_by' => $by->id]);

        return $p->fresh();
    }

    private function progressPage(User $user, IndicatorProgress $p)
    {
        $activityId = $p->indicator->activity_id;

        return Volt::actingAs($user)->test('pages.monitoring.execution-progress')->set('quarter', 4)->set('activityId', $activityId);
    }

    public function test_progress_cannot_be_verified_by_the_person_who_reported_it(): void
    {
        $director = $this->unitDirector();
        $p = $this->submittedProgress($director);

        $this->progressPage($director, $p)->call('verifyProgress', $p->indicator_id);
        $this->assertSame('Submitted', $p->fresh()->status, 'a report is not verified by its own author');

        $this->progressPage($this->finalApprover(), $p)->call('verifyProgress', $p->indicator_id);
        $this->assertSame('Verified', $p->fresh()->status);
        $this->assertSame($this->finalApprover()->id, $p->fresh()->verified_by);
    }

    public function test_the_verify_all_button_leaves_a_users_own_reports_for_someone_else(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $own = $this->submittedProgress($admin);

        Volt::actingAs($admin)->test('pages.monitoring.indicator-approval')->call('approveAll');

        $this->assertSame('Submitted', $own->fresh()->status, 'a report is not verified by its own author');
        $this->assertSame(0, IndicatorProgress::where('quarter', 4)->where('status', 'Submitted')->where('id', '!=', $own->id)->count(), 'everything else was verified');
    }

    public function test_a_verified_progress_figure_is_reopened_by_the_final_approver_with_a_remark(): void
    {
        $p = IndicatorProgress::where('quarter', 4)->where('status', 'Verified')->firstOrFail();

        $this->progressPage($this->unitDirector(), $p)->set('reopenId', $p->indicator_id)->set('remark', 'Wrong number')->call('reopenProgress')->assertForbidden();
        $this->assertSame('Verified', $p->fresh()->status);

        $this->progressPage($this->finalApprover(), $p)->set('reopenId', $p->indicator_id)->set('remark', 'Wrong number')->call('reopenProgress');

        $p->refresh();
        $this->assertSame('Draft', $p->status);
        $this->assertNull($p->verified_by);
        $this->assertSame('Wrong number', $p->rejection_notes);
        $this->assertSame('Wrong number', AuditLog::where('action', 'indicatorprogress.updated')->latest('id')->first()->metadata['remark']);
    }
}
