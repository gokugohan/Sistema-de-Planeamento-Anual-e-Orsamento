<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One budget cycle per fiscal year, following the Timor-Leste OGE process:
 *   1. Council of Ministers sets the ministry's indicative ceiling (teto indicativo)
 *   2. The ministry submits its approved plan to the Comité de Revisão (CRO)
 *   3. The CRO decides the ministry total
 *   4. Parliament approves, the President promulgates  → fiscal_envelopes
 *
 * The submission is frozen in budget_cycle_submission_lines so later edits to
 * activities never change what was actually sent to the CRO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year')->unique();

            $table->decimal('indicative_ceiling', 15, 2)->nullable();
            $table->string('ceiling_reference')->nullable();
            $table->date('ceiling_date')->nullable();

            $table->string('submission_reference')->nullable();
            $table->string('submission_fingerprint', 32)->nullable();
            $table->decimal('submitted_total', 15, 2)->nullable();
            $table->unsignedInteger('submitted_activities')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->decimal('cro_total', 15, 2)->nullable();
            $table->date('cro_decision_date')->nullable();
            $table->string('cro_reference')->nullable();
            $table->text('cro_notes')->nullable();
            $table->foreignId('cro_recorded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('budget_cycle_submission_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_cycle_id')->constrained()->cascadeOnDelete();
            // Ids for linking back while the records exist; codes/text so the snapshot
            // stays readable even if activities or units are later changed or removed.
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->unsignedBigInteger('line_item_id')->nullable();
            $table->string('activity_description', 1000);
            $table->string('directorate_code', 50);
            $table->string('program_code', 50)->nullable();
            $table->string('class_code', 20);
            $table->decimal('amount', 15, 2);
            $table->timestamps();

            $table->index(['budget_cycle_id', 'class_code'], 'bcsl_cycle_class_idx');
            $table->index(['budget_cycle_id', 'directorate_code'], 'bcsl_cycle_dir_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_cycle_submission_lines');
        Schema::dropIfExists('budget_cycles');
    }
};
