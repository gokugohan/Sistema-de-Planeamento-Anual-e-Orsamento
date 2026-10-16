<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The resource plan: when an institution asks for it, a finance officer confirms the budget lines of an
     * activity (with a short justification) before the activity can be certified. Changing the lines afterwards
     * takes the confirmation away again.
     */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->timestamp('budget_confirmed_at')->nullable();
            $table->foreignId('budget_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('budget_justification')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('budget_confirmed_by');
            $table->dropColumn(['budget_confirmed_at', 'budget_justification']);
        });
    }
};
