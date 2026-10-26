<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was decided about the institution's budget for a year (by the budget review committee, the State Budget law, a
 * supplementary budget), by economic category, with the plan as it stood when it was recorded. A reference only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_decisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('stage', 20);
            $table->date('decided_on');
            $table->string('reference', 255)->nullable();
            $table->text('note')->nullable();
            $table->json('amounts');
            $table->decimal('total', 16, 2);
            $table->json('plan_amounts');
            $table->decimal('plan_total', 16, 2);
            $table->foreignId('plan_baseline_id')->nullable()->constrained('plan_baselines')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['fiscal_year', 'decided_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_decisions');
    }
};
