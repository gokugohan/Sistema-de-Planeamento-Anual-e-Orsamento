<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a month or a quarter may be reported: the administration opens a period for a fixed time (like a
     * call for reports). A period with no window is open, so nothing changes until windows are set.
     */
    public function up(): void
    {
        Schema::create('reporting_windows', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('period_type', 10); // month | quarter
            $table->unsignedTinyInteger('period');
            $table->date('opens_on');
            $table->date('closes_on');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fiscal_year', 'period_type', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_windows');
    }
};
