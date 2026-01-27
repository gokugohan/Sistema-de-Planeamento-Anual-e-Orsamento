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
        Schema::table('adjusted_budgets', function (Blueprint $table) {
            $table->renameColumn('budget_proposal_id', 'line_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('adjusted_budgets', function (Blueprint $table) {
            $table->renameColumn('line_item_id', 'budget_proposal_id');
        });
    }

};
