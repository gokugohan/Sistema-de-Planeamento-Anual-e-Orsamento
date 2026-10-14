<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Financial execution of an approved budget line, one row per line and quarter: what was committed
     * (compromisso) and what was paid (pagamento) in that quarter. Like indicator progress it is reported
     * by the unit and counts in the reports only once it has been verified.
     */
    public function up(): void
    {
        Schema::create('budget_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('line_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('quarter');
            $table->decimal('committed', 15, 2)->default(0);
            $table->decimal('paid', 15, 2)->default(0);
            $table->string('status')->default('Draft'); // Draft, Submitted, Verified, Rejected
            $table->text('notes')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['line_item_id', 'quarter']);
            $table->index(['quarter', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_executions');
    }
};
