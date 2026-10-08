<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The envelope a ministry receives only exists once the State Budget law has been
 * approved by Parliament and promulgated by the President. These columns record
 * that legal basis; an envelope cannot be submitted or approved without them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_envelopes', function (Blueprint $table) {
            $table->string('budget_law_reference')->nullable()->after('source_authority');
            $table->date('promulgation_date')->nullable()->after('budget_law_reference');
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_envelopes', function (Blueprint $table) {
            $table->dropColumn(['budget_law_reference', 'promulgation_date']);
        });
    }
};
