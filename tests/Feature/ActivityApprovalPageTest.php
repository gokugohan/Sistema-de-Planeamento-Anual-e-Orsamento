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
use Tests\TestCase;

/** The activity page: the approval trail it shows, and the reason / note its dialogs collect. */
class ActivityApprovalPageTest extends TestCase
{
    use RefreshDatabase;

    private function validated(): Activity
    {
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $a = Activity::create([
            'fiscal_year' => 2027, 'program_id' => Program::firstOrFail()->id, 'directorate_id' => Directorate::where('code', 'UDIG')->value('id'),
            'description' => 'Page test activity', 'quarter' => 1, 'priority' => 'Alta', 'planned_start' => '2027-01-01', 'planned_end' => '2027-12-31',
            'status' => Activity::STATUS_DRAFT,
        ]);
        $a->lineItems()->create(['code' => 'P-1', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 500]);
        DB::table('activities')->where('id', $a->id)->update(['status' => Activity::STATUS_VALIDATED]);

        return $a->fresh();
    }

    private function asHead(): User
    {
        $head = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();
        $this->actingAs($head)->withSession(['locale' => 'en']);
        app()->setLocale('en');

        return $head;
    }

    public function test_returning_without_a_reason_changes_nothing(): void
    {
        $a = $this->validated();
        $this->asHead();

        Volt::test('pages.activities.show', ['activity' => $a])->set('reason', '   ')->call('reject');

        $this->assertSame(Activity::STATUS_VALIDATED, $a->fresh()->status);
    }

    public function test_a_return_keeps_its_reason_and_the_unit_sees_it(): void
    {
        $a = $this->validated();
        $head = $this->asHead();

        Volt::test('pages.activities.show', ['activity' => $a])->set('reason', 'Add the travel budget line.')->call('reject');
        $this->assertSame(Activity::STATUS_REJECTED, $a->fresh()->status);

        // the planner of the unit opens the page and reads why
        $this->actingAs(User::where('email', 'tecnico.udig@gdsc.tl')->firstOrFail());
        Volt::test('pages.activities.show', ['activity' => $a->fresh()])
            ->assertSee('Add the travel budget line.')
            ->assertSee($head->name)
            ->assertSee('Returned for correction');
    }

    public function test_the_approval_shows_who_approved_with_the_note(): void
    {
        $a = $this->validated();
        $head = $this->asHead();

        Volt::test('pages.activities.show', ['activity' => $a])->set('note', 'Fits the ministry ceiling.')->call('approve');

        $this->assertSame(Activity::STATUS_APPROVED, $a->fresh()->status);
        Volt::test('pages.activities.show', ['activity' => $a->fresh()])
            ->assertSee('Approved by '.$head->name)
            ->assertSee('Fits the ministry ceiling.');
    }

    public function test_an_activity_in_the_chain_says_who_it_is_waiting_for(): void
    {
        $a = $this->validated();
        $this->asHead();

        Volt::test('pages.activities.show', ['activity' => $a])->assertSee('Waiting for')->assertSee('The head of the directorate to approve it');
    }
}
