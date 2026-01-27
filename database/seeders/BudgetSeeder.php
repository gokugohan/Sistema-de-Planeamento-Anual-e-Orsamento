<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\FiscalEnvelope;
use App\Models\FiscalEnvelopeCategoryAllocation;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\EconomicClassification;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\AdjustedBudget;

use App\Models\IndicatorProgress;
use Illuminate\Support\Facades\DB;

class BudgetSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Clean up existing budget data
        DB::statement('PRAGMA foreign_keys = OFF;');
        Activity::withoutGlobalScopes()->truncate();
        Indicator::truncate();
        LineItem::truncate();
        AdjustedBudget::truncate();
        FiscalEnvelope::truncate();
        FiscalEnvelopeCategoryAllocation::truncate();

        IndicatorProgress::truncate();
        DB::statement('PRAGMA foreign_keys = ON;');

        $dgaf = Directorate::where('code', 'DGAF')->first(); // Direção Geral de Administração e Finanças
        $gm = Directorate::where('code', 'GM')->first(); // Gabinete do Ministro
        $p1 = Program::where('oge_code', '001')->first();
        $p1->update(['manager_name' => 'Teresa Soares', 'kpis' => '500 public servants trained; 80% satisfaction rate']);
        $p2 = Program::where('oge_code', '002')->first();
        $p2->update(['manager_name' => 'Carlos Belo', 'kpis' => '200 new civil servants recruited; Merit-based selection']);

        $classifications = EconomicClassification::all();
        // SCENARIOS DATA
        $scenarios = [
            2024 => [
                'envelope' => 15000000,
                'proposal' => 15000000,
                'categories' => [
                    '10' => 8000000,
                    '20' => 4000000,
                    '40' => 2000000,
                    '50' => 1000000,
                ]
            ],
            2025 => [
                'envelope' => 21000000,
                'proposal' => 24000000,
                'categories' => [
                    '10' => 10000000,
                    '20' => 6000000,
                    '40' => 3000000,
                    '50' => 2000000,
                ],
                'proposal_split' => [
                    '10' => 11000000,
                    '20' => 7000000,
                    '40' => 3500000,
                    '50' => 2500000,
                ]
            ],
            2026 => [
                'envelope' => 30540000,
                'proposal' => 24540000,
                'categories' => [
                    '10' => 12000000,
                    '20' => 7000000,
                    '30' => 1000000,
                    '40' => 2500000,
                    '50' => 1500000,
                ],
                'proposal_split' => [
                    '10' => 12100000,
                    '20' => 7100000,
                    '30' => 1000000,
                    '40' => 2840000,
                    '50' => 1500000,
                ]
            ],
        ];

        // PRODUCTION DATA (2024, 2025, 2026)
        foreach ($scenarios as $year => $data) {
            // Create Envelope
            if ($year < 2026) {
                $envelope = FiscalEnvelope::create([
                    'fiscal_year' => $year,
                    'total_amount' => $data['envelope'],
                    'approved_date' => now()->subMonths(2),
                    'reference_number' => "OGE/$year/DNO/REF-" . rand(100, 999),
                    'source_authority' => 'DNO',
                    'status' => $data['status'] ?? ($year == 2026 ? 'Approved' : 'Closed'),
                    'start_date' => "$year-01-01",
                    'due_date' => "$year-12-31",
                    'is_test_data' => false,
                ]);


                // Create Category Allocations
                foreach ($data['categories'] as $code => $amount) {
                    $cls = $classifications->where('code', $code)->first();
                    FiscalEnvelopeCategoryAllocation::create([
                        'fiscal_envelope_id' => $envelope->id,
                        'economic_classification_id' => $cls->id,
                        'allocated_amount' => $amount,
                        'planned_amount' => $data['proposal_split'][$code] ?? $amount,
                        'variance_status' => 'balanced'
                    ]);
                }
            }



            // Create Activities & Proposals
            $pSplit = $data['proposal_split'] ?? $data['categories'];
            foreach ($pSplit as $code => $totalProposalForCat) {
                $cls = $classifications->where('code', $code)->first();
                $directorates = Directorate::all();

                if ($directorates->isEmpty())
                    continue;

                $amountPerDirectorate = $totalProposalForCat / $directorates->count();

                foreach ($directorates as $index => $dir) {

                    $this->command->info('Creating Activity for ' . $dir->name . ' for fiscal year ' . $year);
                    $i = $index + 1;
                    $program = ($i % 2 == 0) ? $p2 : $p1;

                    $status = Activity::STATUS_DRAFT;
                    $submittedAt = null;
                    $reviewedAt = null;
                    $approvedAt = null;
                    $reviewedBy = null;
                    $approvedBy = null;

                    // Logic for Historical Status
                    if ($year < 2026) {
                        $status = Activity::STATUS_APPROVED;
                        $submittedAt = now()->subMonth();
                        $reviewedAt = now()->subWeeks(3);
                        $approvedAt = now()->subWeeks(2);
                        $reviewedBy = 1; // Admin
                        $approvedBy = 1; // Admin
                    } elseif ($year == 2026) {
                        // Distribute statuses for testing dashboard
                        // $rand = rand(1, 100);
                        // if ($rand < 20) {
                        //     $status = Activity::STATUS_DRAFT;
                        // } elseif ($rand < 40) {
                        //     $status = Activity::STATUS_SUBMITTED;
                        //     $submittedAt = now()->subDays(2);
                        // } elseif ($rand < 60) {
                        //     $status = Activity::STATUS_REVIEWED;
                        //     $submittedAt = now()->subDays(5);
                        //     $reviewedAt = now()->subDays(1);
                        //     $reviewedBy = 1;
                        // } elseif ($rand < 80) {
                        //     $status = Activity::STATUS_VALIDATED;
                        //     $submittedAt = now()->subDays(10);
                        //     $reviewedAt = now()->subDays(5);
                        //     $reviewedBy = 1;
                        // } elseif ($rand < 90) {
                        //     $status = Activity::STATUS_WAITING_APPROVAL;
                        //     $submittedAt = now()->subDays(12);
                        //     $reviewedAt = now()->subDays(8);
                        //     $reviewedBy = 1;
                        //     // Waiting Approval implicitly has a submittal from DG to Minister, 
                        //     // maybe we track that in 'submitted_at' or a new column? 
                        //     // Current logic uses 'submitted_at' for initial submittal.
                        //     // We will assume the updated_at timestamp or just logical state captures the Minister queue.
                        // } else {
                        //     $status = Activity::STATUS_APPROVED;
                        //     $submittedAt = now()->subMonth();
                        //     $reviewedAt = now()->subWeeks(3);
                        //     $approvedAt = now()->subDays(1);
                        //     $reviewedBy = 1;
                        //     $approvedBy = 1;
                        // }
                    }

                    $activity = Activity::create([
                        'program_id' => $program->id,
                        'code' => ($code == '10' ? 'RH-FORM-' : 'AP-MOD-') . $dir->code . '-' . $year,
                        'directorate_id' => $dir->id,
                        'fiscal_year' => $year,
                        'description' => ($code == '10' ? "Formação ($dir->code)" : "Operacional ($dir->code)") . " - " . $classifications->where('code', $code)->first()->name,
                        'manager_name' => 'Diretor ' . $dir->code,
                        'quarter' => rand(1, 4),
                        'priority' => rand(0, 1) ? 'Alta' : 'Média',
                        'status' => $status,
                        'submitted_at' => $submittedAt,
                        'reviewed_at' => $reviewedAt,
                        'approved_at' => $approvedAt,
                        'reviewed_by' => $reviewedBy,
                        'approved_by' => $approvedBy,
                        'procurement_required' => $code >= 40,
                        'planned_start' => "$year-01-01",
                        'planned_end' => "$year-12-31",
                        'is_test_data' => false,
                    ]);

                    $indicator = Indicator::create([
                        'activity_id' => $activity->id,
                        'name' => "Meta Principal - " . $dir->code,
                        'type' => 'Output',
                        'unit' => 'Percentagem',
                        'annual_target' => 100,
                    ]);

                    // Seed Indicator Progress
                    if ($year <= 2025) {
                        for ($q = 1; $q <= ($year == 2025 ? 2 : 4); $q++) {
                            IndicatorProgress::create([
                                'indicator_id' => $indicator->id,
                                'quarter' => $q,
                                'achieved_value' => 25.0,
                            ]);
                        }
                    }

                    LineItem::create([
                        'activity_id' => $activity->id,
                        'code' => $activity->code . '-' . $cls->code . '-' . $i,
                        'economic_classification_id' => $cls->id,
                        'account_code' => $cls->code . '00-' . rand(1000, 9999),
                        'estimated_amount' => $amountPerDirectorate,
                        'is_test_data' => false,
                    ]);
                }
            }

            if ($year < 2026) {
                $this->distribute($envelope);
            }
        }

        // 3. TESTING / SIMULATION DATA (2027)
        // $testYear = 2027;
        // $testEnvelope = FiscalEnvelope::create([
        //     'fiscal_year' => $testYear,
        //     'total_amount' => 30000000, // 30M
        //     'status' => 'Draft',
        //     'start_date' => "$testYear-01-01",
        //     'due_date' => "$testYear-12-31",
        //     'is_test_data' => true,
        // ]);

        // $directorates = Directorate::all();
        // foreach ($directorates as $index => $dir) {
        //     $program = ($index % 2 == 0) ? $p1 : $p2;

        //     // Create 5 simulation activities per directorate
        //     for ($k = 1; $k <= 5; $k++) {
        //         $activity = Activity::create([
        //             'program_id' => $program->id,
        //             'code' => "SIM-{$dir->code}-{$testYear}-{$k}",
        //             'directorate_id' => $dir->id,
        //             'fiscal_year' => $testYear,
        //             'description' => "Simulação: Nova Iniciativa {$k} ({$dir->code})",
        //             'manager_name' => 'Diretor ' . $dir->code,
        //             'quarter' => rand(1, 4),
        //             'priority' => rand(0, 1) ? 'Alta' : 'Média',
        //             'status' => Activity::STATUS_DRAFT, // Draft
        //             'procurement_required' => rand(0, 1),
        //             'planned_start' => "$testYear-01-01",
        //             'planned_end' => "$testYear-12-31",
        //             'is_test_data' => true,
        //         ]);

        //         Indicator::create([
        //             'activity_id' => $activity->id,
        //             'name' => "Meta Simulação {$k}",
        //             'type' => 'Outcome',
        //             'unit' => '#',
        //             'annual_target' => 50,
        //         ]);

        //         // Random budget item
        //         $randomCls = $classifications->random();
        //         LineItem::create([
        //             'activity_id' => $activity->id,
        //             'code' => $activity->code . '-BUD',
        //             'economic_classification_id' => $randomCls->id,
        //             'estimated_amount' => rand(50000, 250000),
        //             'is_test_data' => true,
        //         ]);
        //     }
        // }
    }

    private function distribute($envelope)
    {
        // Simplified version of the logic in ManageEnvelopes
        foreach ($envelope->categoryAllocations as $allocation) {
            $categoryAmount = (float) $allocation->allocated_amount;
            $classificationId = $allocation->economic_classification_id;

            $proposals = LineItem::where('economic_classification_id', $classificationId)
                ->whereHas('activity', function ($query) use ($envelope) {
                    $query->where('fiscal_year', $envelope->fiscal_year);
                })
                ->get();

            $totalRequested = $proposals->sum('estimated_amount');

            if ($totalRequested > 0) {
                $ratio = $categoryAmount / $totalRequested;
                foreach ($proposals as $proposal) {
                    AdjustedBudget::updateOrCreate(
                        ['line_item_id' => $proposal->id],
                        ['approved_amount' => (float) $proposal->estimated_amount * $ratio, 'status' => Activity::STATUS_APPROVED]
                    );
                }

            }
        }

        // Sync category metrics and simulate expenditure
        foreach ($envelope->categoryAllocations as $allocation) {
            $allocation->calculateVariance();

            if ($envelope->fiscal_year === 2024) {
                // Fully spent
                $allocation->update([
                    'spent_amount' => $allocation->allocated_amount,
                ]);
            } elseif ($envelope->fiscal_year === 2025) {
                // Half spent
                $allocation->update([
                    'spent_amount' => $allocation->allocated_amount * 0.5,
                ]);
            }
        }
    }
}
