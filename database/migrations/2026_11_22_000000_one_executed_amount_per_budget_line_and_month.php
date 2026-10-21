<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Execution is one amount: what a budget line executed in a month. "Committed" goes; "paid" becomes "executed"
 * and keeps its values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('budget_executions', 'committed')) {
            Schema::table('budget_executions', fn ($t) => $t->dropColumn('committed'));
        }
        if (Schema::hasColumn('budget_executions', 'paid')) {
            DB::statement("ALTER TABLE budget_executions CHANGE paid executed DECIMAL(15,2) NOT NULL DEFAULT '0.00'");
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE budget_executions CHANGE executed paid DECIMAL(15,2) NOT NULL DEFAULT '0.00'");
        Schema::table('budget_executions', fn ($t) => $t->decimal('committed', 15, 2)->default(0)->after('month'));
    }
};
