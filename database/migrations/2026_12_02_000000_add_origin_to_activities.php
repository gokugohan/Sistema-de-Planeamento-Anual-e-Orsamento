<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether an activity continues an existing service (the baseline that the forward estimates carry from year to year)
 * or carries out a new policy priority approved for the year (PPG, named in policy_ref). Since the 2027 budget, new
 * policies are funded on top of the baseline and their results are followed separately. Who pays is a different
 * question, answered by the funding source of each budget line. Every activity already in the plan is an existing service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('origin', 20)->default('baseline')->after('priority');
            $table->string('policy_ref', 30)->nullable()->after('origin');
            $table->index(['fiscal_year', 'origin']);
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex(['fiscal_year', 'origin']);
            $table->dropColumn(['origin', 'policy_ref']);
        });
    }
};
