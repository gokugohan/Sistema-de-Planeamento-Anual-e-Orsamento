<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether new activities can still be planned for a year is decided by the final approval of the plan.
     * The final approver (or an administrator) finalizes the plan, which ends planning, and can reopen it
     * with a remark. Nothing stored means "follow the default": planning ends when the budget is approved.
     */
    public function up(): void
    {
        Schema::table('budget_cycles', function (Blueprint $table) {
            $table->string('planning_state', 10)->nullable()->after('fiscal_year'); // 'closed' (finalized) | 'open' (reopened)
            $table->foreignId('planning_by')->nullable()->after('planning_state')->constrained('users')->nullOnDelete();
            $table->timestamp('planning_at')->nullable()->after('planning_by');
            $table->text('planning_remark')->nullable()->after('planning_at');
        });
    }

    public function down(): void
    {
        Schema::table('budget_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('planning_by');
            $table->dropColumn(['planning_state', 'planning_at', 'planning_remark']);
        });
    }
};
