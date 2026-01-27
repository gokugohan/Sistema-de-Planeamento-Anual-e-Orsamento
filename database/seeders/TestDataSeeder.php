<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\Activity;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\EconomicClassification;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        $dgaf = Directorate::where('code', 'DGAF')->first();
        $dgpm = Directorate::where('code', 'DGPM')->first();

        $p1 = Program::where('oge_code', '001')->first();
        $p2 = Program::where('oge_code', '002')->first();

        $ec10 = EconomicClassification::where('code', '10')->first();
        $ec20 = EconomicClassification::where('code', '20')->first();
        $ec40 = EconomicClassification::where('code', '40')->first();

        // Activity 1: Manutenção de Edifícios
        $a1 = Activity::create([
            'program_id' => $p1->id,
            'directorate_id' => $dgaf->id,
            'description' => 'Manutenção Preventiva dos Edifícios Centrais do MAE',
            'priority' => 'Alta',
            'planned_start' => '2025-01-01',
            'planned_end' => '2025-12-31',
            'status' => 'Planeada',
        ]);

        Indicator::create([
            'activity_id' => $a1->id,
            'name' => 'Número de edifícios intervencionados',
            'type' => 'Output',
            'unit' => 'Unidade',
            'annual_target' => 5,
        ]);

        LineItem::create(['activity_id' => $a1->id, 'economic_classification_id' => $ec20->id, 'estimated_amount' => 50000]);

        // Activity 2: Formação de Quadros
        $a2 = Activity::create([
            'program_id' => $p1->id,
            'directorate_id' => $dgaf->id,
            'description' => 'Programa de Capacitação Técnica em Finanças Públicas',
            'priority' => 'Média',
            'planned_start' => '2025-03-01',
            'planned_end' => '2025-11-30',
            'status' => 'Planeada',
        ]);

        Indicator::create([
            'activity_id' => $a2->id,
            'name' => 'Funcionários formados',
            'type' => 'Output',
            'unit' => 'Pessoas',
            'annual_target' => 100,
        ]);

        LineItem::create(['activity_id' => $a2->id, 'economic_classification_id' => $ec20->id, 'estimated_amount' => 25000]);

        // Activity 3: Apoio aos Municípios
        $a3 = Activity::create([
            'program_id' => $p2->id,
            'directorate_id' => $dgpm->id,
            'description' => 'Implementação da Reforma da Descentralização Administrativa',
            'priority' => 'Alta',
            'planned_start' => '2025-01-01',
            'planned_end' => '2025-12-31',
            'status' => 'Planeada',
        ]);

        Indicator::create([
            'activity_id' => $a3->id,
            'name' => 'Municípios com autonomia financeira',
            'type' => 'Outcome',
            'unit' => 'Municípios',
            'annual_target' => 12,
        ]);

        LineItem::create(['activity_id' => $a3->id, 'economic_classification_id' => $ec10->id, 'estimated_amount' => 150000]);
        LineItem::create(['activity_id' => $a3->id, 'economic_classification_id' => $ec40->id, 'estimated_amount' => 75000]);
    }
}

