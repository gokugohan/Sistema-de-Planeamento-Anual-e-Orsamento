<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * By when the plans are to be corrected to a budget decision: the budget circular gives the calendar for submitting
 * the corrected plans (Decreto-Lei n.º 42/2025, art. 19.2 i). The adjustment round of the decision closes on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('budget_decisions', 'plans_due_on')) {
            Schema::table('budget_decisions', function (Blueprint $table) {
                $table->date('plans_due_on')->nullable()->after('new_policy_space');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('budget_decisions', 'plans_due_on')) {
            Schema::table('budget_decisions', fn (Blueprint $table) => $table->dropColumn('plans_due_on'));
        }
    }
};
