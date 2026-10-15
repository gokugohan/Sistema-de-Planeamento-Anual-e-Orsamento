<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The words that go with the numbers. A monthly report says what was done and what got in the way; a
     * quarterly report adds, where targets were not met, why and what will be done, and a rating. Same flow
     * as the figures: the unit writes it (Draft, Submitted), someone else verifies it.
     */
    public function up(): void
    {
        Schema::create('activity_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('period_type', 10); // month | quarter
            $table->unsignedTinyInteger('period');
            $table->text('results')->nullable();
            $table->text('constraints')->nullable();
            $table->text('reasons_not_met')->nullable();
            $table->text('corrective_measures')->nullable();
            $table->string('rating', 20)->nullable(); // On track | Partly | Off track
            $table->string('status', 20)->default('Draft');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('rejection_notes')->nullable();
            $table->timestamps();

            $table->unique(['activity_id', 'period_type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_reports');
    }
};
