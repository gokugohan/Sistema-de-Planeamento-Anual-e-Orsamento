<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\FiscalEnvelope;
use App\Models\EconomicClassification;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('fiscal_envelope_category_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fiscal_envelope_id')->constrained()->onDelete('cascade');
            $table->foreignId('economic_classification_id')->constrained(indexName: 'fe_cat_alloc_class_fk')->onDelete('cascade');
            $table->decimal('allocated_amount', 15, 2);
            $table->decimal('planned_amount', 15, 2)->default(0);
            $table->decimal('variance_amount', 15, 2)->default(0);
            $table->decimal('variance_percentage', 8, 2)->default(0);
            $table->enum('variance_status', ['balanced', 'over_allocated', 'under_allocated'])->default('balanced');
            $table->enum('approval_status', ['draft', 'pending_approval', 'approved', 'rejected'])->default('draft');
            $table->text('approval_notes')->nullable();
            $table->timestamps();

            $table->unique(['fiscal_envelope_id', 'economic_classification_id'], 'envelope_category_unique');
        });

        // Migrate existing JSON data to relational structure
        $this->migrateExistingData();

        // Remove the old JSON column
        Schema::table('fiscal_envelopes', function (Blueprint $table) {
            $table->dropColumn('category_allocations');
        });
    }

    /**
     * Migrate existing JSON data to relational records
     */
    private function migrateExistingData(): void
    {
        $envelopes = FiscalEnvelope::withoutGlobalScopes()->whereNotNull('category_allocations')->get();
        $classifications = EconomicClassification::withoutGlobalScopes()->get()->keyBy('code');

        // Mapping of old JSON keys to economic classification codes
        $mapping = [
            'c1' => '10',
            'c2' => '20',
            'c3' => '30',
            'c4' => '40',
            'c5' => '50'
        ];

        foreach ($envelopes as $envelope) {
            $allocations = $envelope->category_allocations ?? [];

            foreach ($mapping as $oldKey => $code) {
                if (isset($classifications[$code])) {
                    $allocatedAmount = $allocations[$oldKey] ?? 0;

                    // Calculate planned amount from budget proposals for this fiscal year and category
                    $plannedAmount = \DB::table('budget_proposals')
                        ->join('activities', 'budget_proposals.activity_id', '=', 'activities.id')
                        ->where('activities.fiscal_year', $envelope->fiscal_year)
                        ->where('budget_proposals.economic_classification_id', $classifications[$code]->id)
                        ->sum('budget_proposals.estimated_amount');

                    DB::table('fiscal_envelope_category_allocations')->insert([
                        'fiscal_envelope_id' => $envelope->id,
                        'economic_classification_id' => $classifications[$code]->id,
                        'allocated_amount' => $allocatedAmount,
                        'planned_amount' => $plannedAmount,
                        'variance_amount' => $allocatedAmount - $plannedAmount,
                        'variance_percentage' => $plannedAmount > 0
                            ? (($allocatedAmount - $plannedAmount) / $plannedAmount) * 100
                            : ($allocatedAmount > 0 ? 100 : 0),
                        'variance_status' => $this->determineVarianceStatus($allocatedAmount, $plannedAmount),
                        'approval_status' => 'draft',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    /**
     * Determine variance status based on allocated and planned amounts
     */
    private function determineVarianceStatus(float $allocated, float $planned): string
    {
        $variance = $allocated - $planned;

        // Tolerance: ±0.01 (1 cent) considered balanced
        if (abs($variance) <= 0.01) {
            return 'balanced';
        } elseif ($variance > 0) {
            return 'over_allocated';
        } else {
            return 'under_allocated';
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore category_allocations column before dropping the table
        Schema::table('fiscal_envelopes', function (Blueprint $table) {
            $table->json('category_allocations')->nullable();
        });

        // Migrate data back to JSON (optional - for rollback support)
        $envelopes = FiscalEnvelope::all();
        foreach ($envelopes as $envelope) {
            $allocations = DB::table('fiscal_envelope_category_allocations')
                ->where('fiscal_envelope_id', $envelope->id)
                ->get();

            $categoryData = [];
            $classifications = EconomicClassification::withoutGlobalScopes()->get()->keyBy('id');
            $reverseMapping = ['10' => 'c1', '20' => 'c2', '30' => 'c3', '40' => 'c4', '50' => 'c5'];

            foreach ($allocations as $allocation) {
                $classification = $classifications[$allocation->economic_classification_id] ?? null;
                if ($classification && isset($reverseMapping[$classification->code])) {
                    $categoryData[$reverseMapping[$classification->code]] = $allocation->allocated_amount;
                }
            }

            $envelope->update(['category_allocations' => $categoryData]);
        }

        Schema::dropIfExists('fiscal_envelope_category_allocations');
    }
};
