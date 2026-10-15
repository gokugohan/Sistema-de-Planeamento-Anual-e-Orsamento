<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ApprovalDelegation;
use App\Models\Directorate;
use App\Models\User;
use App\Services\ActivityWorkflow;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

/** An interim approver stands in for a director for a set time, with that seat's authority over the unit and below. */
class DelegationTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private User $stand;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');

        $this->director = User::where('email', 'director.udig@goku.tl')->firstOrFail();
        $this->activity = Activity::where('fiscal_year', 2026)->whereIn('directorate_id', Directorate::find($this->director->directorate_id)->getSelfAndDescendantIds())->firstOrFail();
        $this->activity->forceFill(['status' => Activity::STATUS_SUBMITTED])->saveQuietly();

        // someone from another unit, with the planner role and no authority over this one
        $this->stand = User::factory()->create(['directorate_id' => Directorate::where('id', '!=', $this->activity->directorate_id)->whereNotIn('id', Directorate::find($this->director->directorate_id)->getSelfAndDescendantIds())->firstOrFail()->id]);
        $this->stand->assignRole('Planning');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function appoint(string $from = '2026-10-10', string $to = '2026-10-20'): ApprovalDelegation
    {
        return ApprovalDelegation::create(['user_id' => $this->stand->id, 'directorate_id' => $this->director->directorate_id, 'starts_on' => $from, 'ends_on' => $to, 'created_by' => $this->director->id]);
    }

    public function test_without_an_appointment_the_outsider_has_no_say(): void
    {
        $this->assertFalse($this->stand->fresh()->isDirector());
        $this->assertNull(app(ActivityWorkflow::class)->next($this->stand->fresh(), $this->activity));
    }

    public function test_while_in_force_the_interim_acts_for_the_unit_and_not_beyond_it(): void
    {
        $this->appoint();
        $stand = $this->stand->fresh();

        $this->assertTrue($stand->isDirector());
        $this->assertContains($this->activity->directorate_id, $stand->directedUnitIds());
        $this->assertNotNull(app(ActivityWorkflow::class)->next($stand, $this->activity), 'a submitted activity of the unit is theirs to move on');

        $elsewhere = Activity::where('fiscal_year', 2026)->whereNotIn('directorate_id', $stand->directedUnitIds())->where('directorate_id', '!=', $stand->directorate_id)->firstOrFail();
        $elsewhere->forceFill(['status' => Activity::STATUS_SUBMITTED])->saveQuietly();
        $this->assertNull(app(ActivityWorkflow::class)->next($stand, $elsewhere), 'not the units the appointment does not cover');
    }

    public function test_it_ends_by_date_and_when_revoked(): void
    {
        $d = $this->appoint('2026-10-01', '2026-10-13'); // ended yesterday
        $this->assertFalse($this->stand->fresh()->isDirector());

        $d->update(['ends_on' => '2026-10-20']);
        $this->assertTrue($this->stand->fresh()->isDirector());

        $d->update(['revoked_at' => now()]);
        $this->assertFalse($this->stand->fresh()->isDirector());

        $this->appoint('2026-10-20', '2026-10-25'); // starts later
        $this->assertFalse($this->stand->fresh()->isDirector());
    }

    public function test_a_director_appoints_for_their_own_unit_only_and_not_themselves(): void
    {
        $page = Volt::actingAs($this->director)->test('pages.approvals.delegations')
            ->set('unitId', (string) $this->director->directorate_id)->set('userId', (string) $this->stand->id)->set('startsOn', '2026-10-14')->set('endsOn', '2026-10-30')->call('appoint')
            ->assertHasNoErrors();
        $this->assertSame(1, ApprovalDelegation::where('user_id', $this->stand->id)->count());

        $other = Directorate::where('id', '!=', $this->director->directorate_id)->firstOrFail();
        $page->set('unitId', (string) $other->id)->set('userId', (string) $this->stand->id)->set('endsOn', '2026-10-31')->call('appoint')->assertForbidden();

        Volt::actingAs($this->director)->test('pages.approvals.delegations')
            ->set('unitId', (string) $this->director->directorate_id)->set('userId', (string) $this->director->id)->set('startsOn', '2026-10-14')->set('endsOn', '2026-10-30')->call('appoint');
        $this->assertSame(1, ApprovalDelegation::count(), 'a user cannot appoint themselves');
    }

    public function test_the_end_cannot_be_before_the_start_and_the_page_is_for_directors_and_administrators(): void
    {
        Volt::actingAs($this->director)->test('pages.approvals.delegations')
            ->set('unitId', (string) $this->director->directorate_id)->set('userId', (string) $this->stand->id)->set('startsOn', '2026-10-20')->set('endsOn', '2026-10-10')->call('appoint')
            ->assertHasErrors('endsOn');

        $this->actingAs($this->stand)->get(route('approvals.delegations'))->assertForbidden();
        $this->actingAs($this->director)->get(route('approvals.delegations'))->assertOk()->assertSee('Interim approvers');
    }

    public function test_a_director_revokes_and_cannot_touch_another_units_appointment(): void
    {
        $d = $this->appoint();
        Volt::actingAs($this->director)->test('pages.approvals.delegations')->call('revoke', $d->id);
        $this->assertNotNull($d->fresh()->revoked_at);

        $foreign = ApprovalDelegation::create(['user_id' => $this->stand->id, 'directorate_id' => Directorate::where('id', '!=', $this->director->directorate_id)->firstOrFail()->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-20']);
        Volt::actingAs($this->director)->test('pages.approvals.delegations')->call('revoke', $foreign->id)->assertForbidden();
    }
}
