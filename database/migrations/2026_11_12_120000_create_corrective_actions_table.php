<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /** Corrective actions of an activity that is off track or partly on track (an optional rule): who, by when, done or not. */
    public function up(): void
    {
        Schema::create('corrective_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('quarter'); // the quarterly report it answers
            $table->text('description');
            $table->string('owner_name', 150);
            $table->date('due_on')->nullable();
            $table->string('status', 10)->default('Open'); // Open | Done
            $table->text('closing_note')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['activity_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corrective_actions');
    }
};
