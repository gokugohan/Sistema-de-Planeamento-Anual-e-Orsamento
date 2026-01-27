<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add description to economic_classifications
        Schema::table('economic_classifications', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });

        // Add committed and spent fields to fiscal_envelope_category_allocations
        Schema::table('fiscal_envelope_category_allocations', function (Blueprint $table) {
            $table->decimal('committed_amount', 15, 2)->default(0)->after('planned_amount');
            $table->decimal('spent_amount', 15, 2)->default(0)->after('committed_amount');
        });

        // Seed some initial descriptions for standard codes
        $descriptions = [
            '10' => 'Despesas com pessoal do Estado, incluindo salários, vencimentos, subsídios, horas extraordinárias, contribuições sociais e outros benefícios dos funcionários e agentes do ministério.',
            '20' => 'Despesas operacionais necessárias para o funcionamento do ministério, incluindo aquisição de bens de consumo e contratação de serviços, tais como água, eletricidade, telecomunicações, material de escritório, combustível, manutenção, deslocações e viagens.',
            '30' => 'Transferências financeiras sem contrapartida direta, destinadas a outras entidades públicas ou privadas, organizações, instituições ou famílias, incluindo subsídios, apoios financeiros e contribuições correntes.',
            '40' => 'Despesas de investimento de pequena escala, incluindo aquisição de equipamento durável, mobiliário, viaturas ligeiras e pequenas obras ou reabilitações de infraestruturas existentes.',
            '50' => 'Despesas de investimento de grande escala, incluindo construção de novas infraestruturas, grandes projetos de desenvolvimento, obras estruturais e investimentos estratégicos de longo prazo.'
        ];

        foreach ($descriptions as $code => $desc) {
            DB::table('economic_classifications')
                ->where('code', $code)
                ->update(['description' => $desc]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('economic_classifications', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('fiscal_envelope_category_allocations', function (Blueprint $table) {
            $table->dropColumn(['committed_amount', 'spent_amount']);
        });
    }
};
