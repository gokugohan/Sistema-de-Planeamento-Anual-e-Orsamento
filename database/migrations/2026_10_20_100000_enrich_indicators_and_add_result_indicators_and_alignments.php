<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Richer indicators.
     *
     * - An activity indicator says how it is verified, who funds it and in which quarters it is reported
     *   (nothing stored: every quarter), next to its baseline and target.
     * - Programmes and subprogrammes carry their own result indicators (the change they are meant to bring,
     *   not reported quarter by quarter) and the national objectives they contribute to (SDG, strategic plan).
     */
    public function up(): void
    {
        Schema::table('indicators', function (Blueprint $table) {
            $table->text('means_of_verification')->nullable()->after('target_text');
            $table->string('funding_source', 100)->nullable()->after('means_of_verification');
            $table->json('due_quarters')->nullable()->after('funding_source');
        });

        Schema::create('result_indicators', function (Blueprint $table) {
            $table->id();
            $table->morphs('indicatable'); // a programme or a subprogramme
            $table->string('name', 500);
            $table->string('baseline')->nullable();
            $table->string('target')->nullable();
            $table->text('means_of_verification')->nullable();
            $table->timestamps();
        });

        Schema::create('alignments', function (Blueprint $table) {
            $table->id();
            $table->morphs('alignable'); // a programme or a subprogramme
            $table->string('framework', 30);   // SDG, PED, Other
            $table->string('reference', 150);  // e.g. "SDG 9" or "PED 2011-2030, goal 3"
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alignments');
        Schema::dropIfExists('result_indicators');
        Schema::table('indicators', function (Blueprint $table) {
            $table->dropColumn(['means_of_verification', 'funding_source', 'due_quarters']);
        });
    }
};
