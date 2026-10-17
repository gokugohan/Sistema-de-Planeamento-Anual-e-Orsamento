<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The plan is the centre and there is no fiscal envelope, distribution, ceiling or budget cycle: their tables go.
     * (Which directorate has finalized its plan lives in unit_plannings; the amounts in force of an approved
     * activity live in adjusted_budgets, written by its approved version.)
     */
    public function up(): void
    {
        Schema::dropIfExists('budget_cycle_submission_lines');
        Schema::dropIfExists('budget_cycles');
        Schema::dropIfExists('fiscal_envelope_category_allocations');
        Schema::dropIfExists('fiscal_envelopes');

        // sample records that pointed at them
        DB::table('sample_records')->whereIn('model_type', ['App\\Models\\FiscalEnvelope', 'App\\Models\\BudgetCycle', 'fiscal_envelope', 'budget_cycle'])->delete();
    }

    public function down(): void
    {
        // not restored: the envelope no longer exists in the application
    }
};
