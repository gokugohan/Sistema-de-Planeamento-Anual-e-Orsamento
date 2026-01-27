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
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->year('fiscal_year')->default(date('Y') + 1);
            $table->foreignId('program_id')->constrained()->onDelete('cascade');
            $table->string('code')->nullable();
            $table->foreignId('directorate_id')->constrained()->onDelete('cascade');
            $table->text('description');
            $table->string('manager_name')->nullable();
            $table->integer('quarter')->default(1);
            $table->enum('priority', ['Alta', 'Média', 'Baixa'])->default('Média');
            $table->date('planned_start');
            $table->date('planned_end');
            $table->json('execution_quarters')->nullable();
            $table->enum('period_type', [
                'quarterly',
                'semi_annual',
                'annual',
                'custom'
            ])->default('custom');
            $table->string('status')->default('Draft');
            $table->boolean('procurement_required')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
