<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A closed period of the calendar reopened for one unit (and the units below it) until a date, by an administrator. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('window_extensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->string('period_type', 10);
            $table->unsignedTinyInteger('period')->default(1);
            $table->foreignId('directorate_id')->constrained()->cascadeOnDelete();
            $table->date('closes_on');
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['fiscal_year', 'period_type', 'period', 'directorate_id'], 'window_extensions_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('window_extensions');
    }
};
