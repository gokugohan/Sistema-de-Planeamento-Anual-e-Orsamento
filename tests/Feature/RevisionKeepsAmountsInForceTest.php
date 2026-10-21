<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AdjustedBudget;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\LineItem;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\ActivityWorkflow;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** While a revision is pending, the version in force keeps its amounts, even for a line the revision removes. */
#[Group('office')]
class RevisionKeepsAmountsInForceTest extends TestCase
{
    use RefreshDatabase;

    private Activity $activity;

    private User $head;

    private LineItem $extra;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(MtcOfficeSampleDataSeeder::class)->setContainer(app())->run();
        $this->head = User::where('email', 'chefe.gdsc@gdsc.tl')->firstOrFail();

        $this->activity = Activity::where('fiscal_year', 2027)->where('directorate_id', Directorate::where('code', 'UDIG')->value('id'))->firstOrFail();
        $this->activity->forceFill(['status' => Activity::STATUS_VALIDATED])->save();
        $this->extra = $this->activity->lineItems()->create([
            'code' => 'X-1', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 1000,
        ]);
        app(ActivityWorkflow::class)->approve($this->head, $this->activity->fresh());
        $this->activity = $this->activity->fresh();
    }

    private function inForce(): float
    {
        return (float) DB::table('adjusted_budgets as ab')->join('line_items as li', 'li.id', '=', 'ab.line_item_id')
            ->where('li.activity_id', $this->activity->id)->sum('ab.approved_amount');
    }

    private function revise(): Activity
    {
        app(ActivityWorkflow::class)->reopen($this->head, $this->activity->fresh(), 'Remove a line');

        return $this->activity->fresh();
    }

    public function test_removing_a_line_in_a_revision_keeps_the_total_in_force(): void
    {
        $before = $this->inForce();
        $this->assertGreaterThanOrEqual(1000, $before);
        $rollup = Directorate::findOrFail($this->activity->directorate_id)->getTotalApprovedRollup(2027);

        $revision = $this->revise();
        $this->extra->fresh()->delete();

        $this->assertEqualsWithDelta($before, $this->inForce(), 0.001, 'the amount in force does not change before the revision is approved');
        $this->assertEqualsWithDelta($rollup, Directorate::findOrFail($this->activity->directorate_id)->getTotalApprovedRollup(2027), 0.001);
        $this->assertFalse($revision->lineItems()->whereKey($this->extra->id)->exists(), 'the revision no longer has the line');
        $this->assertSame(1, LineItem::onlyTrashed()->whereKey($this->extra->id)->count(), 'the line is only set aside');
    }

    public function test_approving_the_revision_replaces_the_amounts_in_force(): void
    {
        $before = $this->inForce();
        $revision = $this->revise();
        $this->extra->fresh()->delete();

        app(ActivityVersions::class)->approved($revision->fresh(), $this->head);

        $this->assertEqualsWithDelta($before - 1000, $this->inForce(), 0.001);
        $this->assertSame(0, LineItem::withTrashed()->whereKey($this->extra->id)->count());
        $this->assertSame(0, AdjustedBudget::where('line_item_id', $this->extra->id)->count());
    }

    public function test_a_line_that_was_never_in_force_is_deleted_for_good(): void
    {
        $revision = $this->revise();
        $new = $revision->lineItems()->create(['code' => 'X-2', 'economic_classification_id' => EconomicClassification::firstOrFail()->id, 'estimated_amount' => 50]);

        $new->delete();

        $this->assertSame(0, LineItem::withTrashed()->whereKey($new->id)->count());
    }

    public function test_the_plan_totals_leave_out_the_line_the_revision_removed(): void
    {
        $planned = fn () => (float) $this->activity->lineItems()->sum('estimated_amount');
        $with = $planned();
        $this->revise();
        $this->extra->fresh()->delete();

        $this->assertEqualsWithDelta($with - 1000, $planned(), 0.001, 'what is planned in the revision excludes it');
    }
}
