<?php

namespace Database\Seeders;

use App\Models\EconomicClassification;
use Illuminate\Database\Seeder;

class EconomicClassificationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $classifications = [
            ['code' => '10', 'name' => 'Salários e Vencimentos'],
            ['code' => '20', 'name' => 'Bens e Serviços'],
            ['code' => '30', 'name' => 'Transferências Públicas'],
            ['code' => '40', 'name' => 'Capital Menor'],
            ['code' => '50', 'name' => 'Capital de Desenvolvimento'],
        ];

        foreach ($classifications as $item) {
            EconomicClassification::firstOrCreate(
                ['code' => $item['code']],
                $item
            );
        }
    }
}
