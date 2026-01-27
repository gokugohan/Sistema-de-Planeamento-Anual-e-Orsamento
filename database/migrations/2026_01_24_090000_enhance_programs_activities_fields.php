<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Enhance Programs
        Schema::table('programs', function (Blueprint $table) {
            $table->string('manager_name')->nullable()->after('oge_code');
            $table->text('kpis')->nullable()->after('objective');
        });



        // Enhance Budget Proposals (Line Items)
        Schema::table('budget_proposals', function (Blueprint $table) {
            $table->string('code')->nullable()->after('activity_id');
            $table->string('account_code')->nullable()->after('economic_classification_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn(['manager_name', 'kpis']);
        });



        Schema::table('budget_proposals', function (Blueprint $table) {
            $table->dropColumn(['code', 'account_code']);
        });
    }
};
