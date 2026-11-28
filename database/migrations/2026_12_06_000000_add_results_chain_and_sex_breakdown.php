<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The results chain of the State Budget's annual plans (Anexo K): a programme's outcomes (Objetivo de Médio Prazo)
 * and a subprogramme's outputs (Resultado de Curto Prazo), each output serving one outcome, and each activity serving
 * one output. Also the division code the budget gives each unit (Div), and the men and women in an indicator's
 * baseline and target, as the budget books print them ("24 (M = 18 e F = 6)").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('result_indicators', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->after('indicatable_id');
            $table->text('statement')->nullable()->after('code');
            $table->foreignId('outcome_id')->nullable()->after('statement')->constrained('result_indicators')->nullOnDelete();
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('output_id')->nullable()->after('subprogram_id')->constrained('result_indicators')->nullOnDelete();
        });

        Schema::table('directorates', function (Blueprint $table) {
            $table->string('budget_code', 20)->nullable()->after('code');
        });

        Schema::table('indicators', function (Blueprint $table) {
            $table->decimal('baseline_men', 15, 2)->nullable()->after('baseline');
            $table->decimal('baseline_women', 15, 2)->nullable()->after('baseline_men');
            $table->decimal('target_men', 15, 2)->nullable()->after('annual_target');
            $table->decimal('target_women', 15, 2)->nullable()->after('target_men');
        });
    }

    public function down(): void
    {
        Schema::table('indicators', fn (Blueprint $table) => $table->dropColumn(['baseline_men', 'baseline_women', 'target_men', 'target_women']));
        Schema::table('directorates', fn (Blueprint $table) => $table->dropColumn('budget_code'));
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('output_id');
        });
        Schema::table('result_indicators', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outcome_id');
            $table->dropColumn(['code', 'statement']);
        });
    }
};
