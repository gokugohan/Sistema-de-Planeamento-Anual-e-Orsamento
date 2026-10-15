<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The budget structure has three levels: programme, subprogramme, activity. A subprogramme belongs to
     * a programme and so to its fiscal year (programmes are registered per year). An activity names the
     * subprogramme it is part of; where a programme has none it simply stays under the programme.
     */
    public function up(): void
    {
        Schema::create('subprograms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->text('objective')->nullable();
            $table->timestamps();

            $table->unique(['program_id', 'code']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('subprogram_id')->nullable()->after('program_id')->constrained('subprograms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subprogram_id');
        });
        Schema::dropIfExists('subprograms');
    }
};
