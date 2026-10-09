<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\AdjustedBudget;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\FiscalEnvelopeCategoryAllocation;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\User;
use App\Services\EnvelopeDistributor;
use Database\Seeders\RolesAndPermissionsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class EnvelopeDistributionTest extends TestCase
{
    use RefreshDatabase;

    private EconomicClassification $goods;

    private Directorate $dir;

    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        $this->goods = EconomicClassification::create(['code' => '20', 'name' => 'Bens e Serviços']);
        $this->dir = Directorate::create(['code' => 'DNTT', 'name' => 'DNTT', 'type' => 'National Directorate', 'active' => true]);
        $this->program = Program::create(['oge_code' => '002', 'name' => 'Transportes Terrestres']);
    }

    private function envelope(float $goodsAmount, string $status = FiscalEnvelope::STATUS_APPROVED): FiscalEnvelope
    {
        $envelope = FiscalEnvelope::create([
            'fiscal_year' => 2027, 'total_amount' => $goodsAmount, 'status' => $status,
            'reference_number' => 'OGE/2027', 'start_date' => '2027-01-01', 'due_date' => '2027-12-31',
            'budget_law_reference' => 'Lei n.º 1/2027', 'promulgation_date' => '2026-12-20',
        ]);
        FiscalEnvelopeCategoryAllocation::create([
            'fiscal_envelope_id' => $envelope->id,
            'economic_classification_id' => $this->goods->id,
            'allocated_amount' => $goodsAmount,
        ]);

        return $envelope;
    }

    private function line(string $status, float $amount): LineItem
    {
        $activity = Activity::create([
            'fiscal_year' => 2027, 'program_id' => $this->program->id, 'directorate_id' => $this->dir->id,
            'description' => "Activity {$status} {$amount} ".uniqid(), 'quarter' => 1, 'priority' => 'Alta',
            'planned_start' => '2027-01-01', 'planned_end' => '2027-03-31', 'status' => $status,
        ]);

        return LineItem::create([
            'activity_id' => $activity->id,
            'economic_classification_id' => $this->goods->id,
            'estimated_amount' => $amount,
        ]);
    }

    private function approvedAmount(LineItem $line): ?float
    {
        $v = AdjustedBudget::where('line_item_id', $line->id)->value('approved_amount');

        return $v === null ? null : (float) $v;
    }

    public function test_only_approved_activities_receive_money(): void
    {
        $approved = $this->line(Activity::STATUS_APPROVED, 1000);
        $draft = $this->line(Activity::STATUS_DRAFT, 1000);
        $rejected = $this->line(Activity::STATUS_REJECTED, 1000);
        $submitted = $this->line(Activity::STATUS_SUBMITTED, 1000);

        (new EnvelopeDistributor)->distribute($this->envelope(1000));

        $this->assertSame(1000.0, $this->approvedAmount($approved));
        $this->assertNull($this->approvedAmount($draft));
        $this->assertNull($this->approvedAmount($rejected));
        $this->assertNull($this->approvedAmount($submitted));
    }

    public function test_a_larger_envelope_never_funds_more_than_was_planned(): void
    {
        $a = $this->line(Activity::STATUS_APPROVED, 300);
        $b = $this->line(Activity::STATUS_APPROVED, 700);

        $summary = (new EnvelopeDistributor)->distribute($this->envelope(5000));

        $this->assertSame(300.0, $this->approvedAmount($a));
        $this->assertSame(700.0, $this->approvedAmount($b));
        $this->assertEquals(1000, $summary['distributed']);
        $this->assertEquals(4000, $summary['unallocated']);
    }

    public function test_a_cut_is_shared_pro_rata_and_adds_up_to_the_cent(): void
    {
        $lines = [
            $this->line(Activity::STATUS_APPROVED, 100),
            $this->line(Activity::STATUS_APPROVED, 100),
            $this->line(Activity::STATUS_APPROVED, 100),
        ];

        (new EnvelopeDistributor)->distribute($this->envelope(100)); // 100 over 3 equal lines

        $amounts = array_map(fn ($l) => $this->approvedAmount($l), $lines);
        $this->assertEqualsWithDelta(100.00, array_sum($amounts), 0.0001);
        $this->assertEqualsCanonicalizing([33.34, 33.33, 33.33], $amounts);
    }

    public function test_lines_that_lost_approval_are_cleared_on_redistribution(): void
    {
        $line = $this->line(Activity::STATUS_APPROVED, 500);
        $envelope = $this->envelope(500);
        (new EnvelopeDistributor)->distribute($envelope);
        $this->assertSame(500.0, $this->approvedAmount($line));

        $line->activity->update(['status' => Activity::STATUS_DRAFT]);
        $summary = (new EnvelopeDistributor)->distribute($envelope);

        $this->assertNull($this->approvedAmount($line));
        $this->assertSame(1, $summary['cleared']);
    }

    public function test_preview_matches_what_distribution_writes(): void
    {
        $this->line(Activity::STATUS_APPROVED, 250);
        $this->line(Activity::STATUS_APPROVED, 750);
        $envelope = $this->envelope(600);

        $preview = (new EnvelopeDistributor)->preview($envelope);
        (new EnvelopeDistributor)->distribute($envelope);

        foreach ($preview[0]['lines'] as $row) {
            $this->assertSame($row['new_approved'], (float) AdjustedBudget::where('line_item_id', $row['line_item_id'])->value('approved_amount'));
        }
    }

    public function test_an_envelope_that_is_not_approved_cannot_be_distributed(): void
    {
        $line = $this->line(Activity::STATUS_APPROVED, 100);

        $this->expectException(DomainException::class);
        try {
            (new EnvelopeDistributor)->distribute($this->envelope(100, FiscalEnvelope::STATUS_DRAFT));
        } finally {
            $this->assertNull($this->approvedAmount($line));
        }
    }

    public function test_the_workflow_enforces_legal_basis_and_order(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $minister = User::factory()->create();
        $minister->assignRole('Minister');

        $envelope = $this->envelope(1000, FiscalEnvelope::STATUS_DRAFT);
        $envelope->update(['budget_law_reference' => null, 'promulgation_date' => null]);

        // Cannot submit without the promulgated budget law.
        $this->actingAs($admin);
        Volt::test('pages.budget.manage-envelopes')->call('submitForApproval', $envelope->id);
        $this->assertSame(FiscalEnvelope::STATUS_DRAFT, $envelope->fresh()->status);

        // Minister cannot approve a draft.
        $this->actingAs($minister);
        Volt::test('pages.budget.manage-envelopes')->call('approve', $envelope->id);
        $this->assertSame(FiscalEnvelope::STATUS_DRAFT, $envelope->fresh()->status);

        // With the legal basis recorded: submit, then approve.
        $envelope->update(['budget_law_reference' => 'Lei n.º 1/2027', 'promulgation_date' => '2026-12-20']);
        $this->actingAs($admin);
        Volt::test('pages.budget.manage-envelopes')->call('submitForApproval', $envelope->id);
        $this->assertSame(FiscalEnvelope::STATUS_PENDING, $envelope->fresh()->status);

        $this->actingAs($minister);
        Volt::test('pages.budget.manage-envelopes')->call('approve', $envelope->id);
        $this->assertSame(FiscalEnvelope::STATUS_APPROVED, $envelope->fresh()->status);
    }

    public function test_users_without_envelope_permission_cannot_submit(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $planner = User::factory()->create();
        $planner->assignRole('Planning');
        $envelope = $this->envelope(1000, FiscalEnvelope::STATUS_DRAFT);

        $this->actingAs($planner);
        Volt::test('pages.budget.manage-envelopes')->call('submitForApproval', $envelope->id)->assertForbidden();
        $this->assertSame(FiscalEnvelope::STATUS_DRAFT, $envelope->fresh()->status);
    }
}
