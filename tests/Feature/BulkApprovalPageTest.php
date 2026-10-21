<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Bulk approval on the Plan Approval page: select, review the server's summary, confirm, read what was left out. */
#[Group('office')]
class BulkApprovalPageTest extends TestCase
{
    use RefreshDatabase;

    private function ready(string $unit, string $name, int $amount, string $status = Activity::STATUS_VALIDATED): Activity
    {
        $a = Activity::create([
            'fiscal_year' => 2026, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => Directorate::where('code', $unit)->value('id'),
            'description' => $name, 'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2026-01-01', 'planned_end' => '2026-12-31',
            'status' => Activity::STATUS_DRAFT,
        ]);
        $a->lineItems()->create(['code' => 'B-'.$a->id, 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => $amount]);
        DB::table('activities')->where('id', $a->id)->update(['status' => $status]);

        return $a->fresh();
    }

    private function admin(): User
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin)->withSession(['locale' => 'en']);
        app()->setLocale('en');

        return $admin;
    }

    private function page()
    {
        return Volt::test('pages.planning.paa-approval')->set('queue', 'approval');
    }

    public function test_selecting_everything_that_is_ready_and_reading_the_summary(): void
    {
        $this->admin();
        $a = $this->ready('UDIG', 'Ready A', 1000);
        $b = $this->ready('USC', 'Ready B', 2500);
        $this->ready('UDIG', 'Not ready', 700, Activity::STATUS_SUBMITTED);

        $page = $this->page()->call('selectAllReady');
        $page->assertSet('selected', [(string) $a->id, (string) $b->id]);

        $page->call('prepareBulk');
        $summary = $page->get('bulkSummary');
        $this->assertSame(2, $summary['count']);
        $this->assertSame(3500.0, $summary['total']);
        $this->assertSame(['UDIG' => 1, 'USC' => 1], $summary['units']);
        $this->assertSame(0, $summary['left_out']);
    }

    public function test_confirming_approves_the_batch_with_one_note_and_reports_what_was_left_out(): void
    {
        $admin = $this->admin();
        $a = $this->ready('UDIG', 'Ready A', 1000);
        $b = $this->ready('USC', 'Ready B', 2500);
        $late = $this->ready('UDIG', 'Turned into a draft meanwhile', 300);

        $page = $this->page()->set('selected', [(string) $a->id, (string) $b->id, (string) $late->id])->call('prepareBulk');
        DB::table('activities')->where('id', $late->id)->update(['status' => Activity::STATUS_DRAFT]); // changed after the selection

        $page->set('note', 'Reviewed with the planners.')->call('approveSelected');

        $this->assertSame(Activity::STATUS_APPROVED, $a->fresh()->status);
        $this->assertSame($admin->id, $b->fresh()->approved_by);
        $this->assertSame(Activity::STATUS_DRAFT, $late->fresh()->status);

        $result = $page->get('result');
        $this->assertCount(2, $result['approved']);
        $this->assertCount(1, $result['skipped']);
        $page->assertSee('Turned into a draft meanwhile')->assertSet('selected', []);
    }

    public function test_nothing_ready_means_no_confirmation(): void
    {
        $this->admin();
        $draft = $this->ready('UDIG', 'Only a draft', 100, Activity::STATUS_DRAFT);

        $page = $this->page()->set('selected', [(string) $draft->id])->call('prepareBulk');

        $this->assertNull($page->get('bulkSummary'));
    }

    public function test_returning_from_the_queue_needs_a_reason(): void
    {
        $this->admin();
        $a = $this->ready('UDIG', 'To return', 100);

        $page = $this->page()->set('returnId', $a->id)->set('reason', '')->call('returnActivity');
        $this->assertSame(Activity::STATUS_VALIDATED, $a->fresh()->status);

        $page->set('reason', 'Check the amounts.')->call('returnActivity');
        $this->assertSame(Activity::STATUS_REJECTED, $a->fresh()->status);
    }

    public function test_the_selection_resets_when_the_queue_changes(): void
    {
        $this->admin();
        $a = $this->ready('UDIG', 'Ready', 100);

        $this->page()->set('selected', [(string) $a->id])->call('setQueue', 'review')->assertSet('selected', []);
    }
}
