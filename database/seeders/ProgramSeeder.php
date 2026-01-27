<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Program;

class ProgramSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $programs = [
            ['name' => 'Administração e Gestão Institucional', 'oge_code' => '001', 'objective' => 'Garantir o funcionamento administrativo do ministério.'],
            ['name' => 'Governação e Descentralização', 'oge_code' => '002', 'objective' => 'Promover a descentralização administrativa.'],
            ['name' => 'Planeamento Territorial', 'oge_code' => '003', 'objective' => 'Garantir o ordenamento do território.'],
            ['name' => 'Desenvolvimento Rural Integrado', 'oge_code' => '004', 'objective' => 'Fomentar o desenvolvimento nas zonas rurais.'],
        ];

        foreach ($programs as $prog) {
            Program::firstOrCreate(
                ['oge_code' => $prog['oge_code']],
                $prog
            );
        }
    }
}
