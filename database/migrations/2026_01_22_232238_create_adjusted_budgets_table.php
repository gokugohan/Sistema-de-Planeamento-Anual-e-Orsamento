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
        Schema::create('adjusted_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_proposal_id')->constrained()->onDelete('cascade');
            $table->decimal('approved_amount', 15, 2);
            $table->enum('status', ['Approved', 'Partial', 'Cut'])->default('Approved');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adjusted_budgets');
    }
};
