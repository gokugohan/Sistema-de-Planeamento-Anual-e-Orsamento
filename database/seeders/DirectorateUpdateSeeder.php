<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Directorate;

class DirectorateUpdateSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            'DGAF' => [
                'type' => 'Direção Geral',
                'description' => 'Administração e Finanças do Ministério.',
                'director_name' => 'Maria Silva',
                'director_email' => 'maria.silva@msa.gov.tl',
            ],
            'DGPM' => [
                'type' => 'Direção Geral',
                'description' => 'Planeamento e Monitorização Estratégica.',
                'director_name' => 'João Santos',
                'director_email' => 'joao.santos@msa.gov.tl',
            ],
            'DND' => [
                'type' => 'Direção Nacional',
                'description' => 'Desenvolvimento e Descentralização.',
                'director_name' => 'Ana Costa',
                'director_email' => 'ana.costa@msa.gov.tl',
            ],
        ];

        foreach ($data as $code => $fields) {
            Directorate::where('code', $code)->update($fields);
        }
    }
}
