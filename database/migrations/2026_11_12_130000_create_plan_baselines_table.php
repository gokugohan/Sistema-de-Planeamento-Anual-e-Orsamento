<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * A baseline of the approved plan of a year (an optional rule): which version of each activity was in force when it
     * was recorded, so that the final report can be printed as it stood then. Recording one approves nothing.
     */
    public function up(): void
    {
        Schema::create('plan_baselines', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->json('versions'); // activity id => version number in force
            $table->unsignedInteger('activity_count');
            $table->decimal('total', 15, 2);
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->index('fiscal_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_baselines');
    }
};
