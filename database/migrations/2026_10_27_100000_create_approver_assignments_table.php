<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Named people for the steps of the approval chain, per unit: who prepares (submits), who certifies
     * (reviews and validates) and who approves. A unit without a list for a step keeps the default rule
     * (the director of the unit, or of the office, acts), so nothing changes until a list is made.
     */
    public function up(): void
    {
        Schema::create('approver_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('directorate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('step', 10); // prepare | certify | approve
            $table->unsignedSmallInteger('position')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['directorate_id', 'user_id', 'step']);
            $table->index(['directorate_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approver_assignments');
    }
};
