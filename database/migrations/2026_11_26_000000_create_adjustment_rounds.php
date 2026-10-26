<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An adjustment round: after a budget decision, the directorates revise the activities they choose so that the plan
 * follows it. Each directorate of the round has a target reduction and confirms when its adjustment is complete; the
 * activities revised for the round are kept with the amount they had in force when their revision started.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('adjustment_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->foreignId('budget_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->date('closes_on');
            $table->text('instructions')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('closing_note', 2000)->nullable();
            $table->timestamps();

            $table->index(['fiscal_year', 'closed_at']);
        });

        Schema::create('adjustment_round_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('directorate_id')->constrained()->cascadeOnDelete();
            $table->decimal('target', 16, 2)->default(0);
            $table->decimal('plan_at_start', 16, 2)->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('remark', 2000)->nullable();
            $table->timestamps();

            $table->unique(['adjustment_round_id', 'directorate_id'], 'adjustment_round_units_unique');
        });

        Schema::create('adjustment_round_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('adjustment_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_before', 16, 2)->default(0);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['adjustment_round_id', 'activity_id'], 'adjustment_round_activities_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adjustment_round_activities');
        Schema::dropIfExists('adjustment_round_units');
        Schema::dropIfExists('adjustment_rounds');
    }
};
