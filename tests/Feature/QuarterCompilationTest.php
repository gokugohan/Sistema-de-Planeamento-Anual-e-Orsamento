<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\ActivityReport;
use App\Models\Directorate;
use App\Models\User;
use App\Services\ApprovalInbox;
use App\Services\PerformanceReport;
use App\Services\QuarterCompilation;
use Carbon\Carbon;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** The director compiles the quarter's text from the unit's reports; the printed report uses it and the unit's text is kept. */
#[Group('office')]
class QuarterCompilationTest extends TestCase
{
    use RefreshDatabase;

    private User $director;

    private Activity $activity;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 10:00:00');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        app()->setLocale('en');
        session(['fiscal_year' => 2026]);

        $this->director = User::where('email', 'director.udig@gdsc.tl')->firstOrFail();
        $this->activity = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereHas('lineItems.adjustedBudget')
            ->whereIn('directorate_id', Directorate::find($this->director->directorate_id)->getSelfAndDescendantIds())->firstOrFail();
        ActivityReport::query()->delete();
        foreach ([7 => 'Tender opened', 8 => 'Tender awarded', 9 => 'Works started'] as $m => $text) {
            ActivityReport::create(['activity_id' => $this->activity->id, 'period_type' => 'month', 'period' => $m, 'results' => $text, 'constraints' => $m === 8 ? 'Late funds' : null, 'status' => 'Verified']);
        }
        ActivityReport::create(['activity_id' => $this->activity->id, 'period_type' => 'quarter', 'period' => 3, 'results' => 'UNIT QUARTER TEXT', 'status' => 'Verified']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_draft_is_built_from_the_verified_reports_and_ignores_drafts(): void
    {
        ActivityReport::create(['activity_id' => $this->activity->id, 'period_type' => 'month', 'period' => 6, 'results' => 'OTHER QUARTER', 'status' => 'Verified']);
        ActivityReport::where('period', 9)->where('period_type', 'month')->update(['status' => 'Draft']);

        $d = app(QuarterCompilation::class)->draft($this->activity, 3);

        $this->assertStringContainsString('July: Tender opened', $d['results']);
        $this->assertStringContainsString('August: Tender awarded', $d['results']);
        $this->assertStringContainsString('UNIT QUARTER TEXT', $d['results']);
        $this->assertStringNotContainsString('Works started', $d['results'], 'an unverified month is not drafted');
        $this->assertStringNotContainsString('OTHER QUARTER', $d['results']);
        $this->assertStringContainsString('August: Late funds', $d['constraints']);
    }

    public function test_the_director_drafts_edits_and_saves_without_touching_the_units_text(): void
    {
        $page = Volt::actingAs($this->director)->test('pages.reports.compile-quarter')->set('quarter', 3)
            ->call('draft', $this->activity->id);
        $this->assertStringContainsString('Tender awarded', $page->get('form')[$this->activity->id]['results']);

        $page->set("form.{$this->activity->id}.results", 'Tender done and works started.')->call('save', $this->activity->id);

        $q = ActivityReport::where(['activity_id' => $this->activity->id, 'period_type' => 'quarter', 'period' => 3])->firstOrFail();
        $this->assertSame('Tender done and works started.', $q->compiled_results);
        $this->assertSame($this->director->id, $q->compiled_by);
        $this->assertNotNull($q->compiled_at);
        $this->assertSame('UNIT QUARTER TEXT', $q->results, 'what the unit wrote is kept');
    }

    public function test_the_printed_quarter_uses_the_compiled_text_and_a_month_does_not(): void
    {
        app(QuarterCompilation::class)->save($this->director, $this->activity, 3, 'COMPILED BY DIRECTOR', null);
        $row = fn (array $report) => collect($report['tree'])->flatMap(fn ($p) => $p['subprograms']->flatMap(fn ($s) => $s['rows'])->merge($p['loose']))->first(fn ($r) => $r['activity']->id === $this->activity->id);

        $quarter = app(PerformanceReport::class)->build(2026, 'quarter', 3, $this->director);
        $month = app(PerformanceReport::class)->build(2026, 'month', 9, $this->director);

        $this->assertSame('COMPILED BY DIRECTOR', $row($quarter)['results']);
        $this->assertTrue($row($quarter)['compiled']);
        $this->assertSame('Works started', $row($month)['results'], 'the month report keeps the unit\'s monthly text');
    }

    public function test_a_compiled_text_on_an_unverified_row_prints_but_its_other_fields_do_not(): void
    {
        ActivityReport::where('period_type', 'quarter')->delete();
        ActivityReport::create(['activity_id' => $this->activity->id, 'period_type' => 'quarter', 'period' => 3, 'results' => 'x', 'reasons_not_met' => 'UNVERIFIED REASON', 'rating' => 'Partly', 'status' => 'Draft']);
        app(QuarterCompilation::class)->save($this->director, $this->activity, 3, 'COMPILED', null);

        $r = collect(app(PerformanceReport::class)->build(2026, 'quarter', 3, $this->director)['tree'])->flatMap(fn ($p) => $p['subprograms']->flatMap(fn ($s) => $s['rows'])->merge($p['loose']))->first(fn ($r) => $r['activity']->id === $this->activity->id);

        $this->assertSame('COMPILED', $r['results']);
        $this->assertNull($r['reasons_not_met']);
        $this->assertNull($r['rating']);
    }

    public function test_only_those_who_direct_the_unit_compile(): void
    {
        $planner = User::factory()->create(['directorate_id' => $this->activity->directorate_id]);
        $planner->assignRole('Planning');

        $this->actingAs($planner)->get(route('reports.compile'))->assertForbidden();
        $this->actingAs($this->director)->get(route('reports.compile'))->assertOk()->assertSee('Compile the quarter');
        $this->expectException(HttpException::class);
        app(QuarterCompilation::class)->save($planner, $this->activity, 3, 'nope', null);
    }

    public function test_a_director_does_not_see_activities_of_other_units(): void
    {
        $other = Activity::where('fiscal_year', 2026)->where('status', 'Approved')->whereNotIn('directorate_id', Directorate::find($this->director->directorate_id)->getSelfAndDescendantIds())->first();
        if (! $other) {
            $this->markTestSkipped('The sample has no activity outside this unit.');
        }

        $ids = app(QuarterCompilation::class)->activities($this->director, 2026)->pluck('id');
        $this->assertNotContains($other->id, $ids->all());
        $this->assertContains($this->activity->id, $ids->all());
    }

    public function test_the_inbox_asks_the_director_to_compile_the_quarter_that_ended_and_clears_when_done(): void
    {
        $find = fn () => collect(app(ApprovalInbox::class)->items($this->director, 2026))->first(fn ($i) => str_contains($i['text'], 'no compiled text for Q3'));

        $this->assertNotNull($find());

        $svc = app(QuarterCompilation::class);
        $svc->activities($this->director, 2026)->get()->each(fn ($a) => $svc->save($this->director, $a, 3, 'done', null));

        $this->assertNull($find());
    }
}
