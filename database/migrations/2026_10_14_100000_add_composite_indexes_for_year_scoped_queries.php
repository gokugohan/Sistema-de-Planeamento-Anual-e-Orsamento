<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Almost every screen reads "the activities of one fiscal year (production data)" and sums their
 * budget lines. With the single-column indexes the database picked the weak is_test_data one and read
 * thousands of rows per page; these composites match the filters the application actually uses and
 * let the sums be answered from the index alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->index(['fiscal_year', 'is_test_data', 'status', 'directorate_id'], 'activities_year_prod_status_unit_idx');
            $table->index(['fiscal_year', 'is_test_data', 'program_id'], 'activities_year_prod_program_idx');
        });

        Schema::table('line_items', function (Blueprint $table) {
            $table->index(['activity_id', 'is_test_data', 'economic_classification_id', 'estimated_amount'], 'line_items_activity_prod_class_amount_idx');
        });

        Schema::table('adjusted_budgets', function (Blueprint $table) {
            $table->index(['line_item_id', 'approved_amount'], 'adjusted_budgets_line_item_amount_idx');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('activities_year_prod_status_unit_idx');
            $table->dropIndex('activities_year_prod_program_idx');
        });

        Schema::table('line_items', function (Blueprint $table) {
            $table->dropIndex('line_items_activity_prod_class_amount_idx');
        });

        Schema::table('adjusted_budgets', function (Blueprint $table) {
            $table->dropIndex('adjusted_budgets_line_item_amount_idx');
        });
    }
};
