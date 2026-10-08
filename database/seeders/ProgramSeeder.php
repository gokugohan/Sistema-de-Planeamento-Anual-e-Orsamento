<?php

namespace Database\Seeders;

use App\Models\Program;
use Illuminate\Database\Seeder;

/**
 * Working programmes for the Ministério dos Transportes e Comunicações,
 * derived from the sectors in Decreto-Lei 75/2023 as amended by DL 12/2026.
 *
 * IMPORTANT: the oge_code values are PLACEHOLDERS. They are not the Ministry of
 * Finance's official OGE programme codes. Replace names/codes with the official
 * ones (Management > Entities > Programs) before using the data for submission.
 */
class ProgramSeeder extends Seeder
{
    public function run(): void
    {
        $programs = [
            ['oge_code' => '001', 'name' => 'Administração e Gestão Institucional', 'objective' => 'Garantir o funcionamento administrativo, financeiro e de recursos humanos do ministério.'],
            ['oge_code' => '002', 'name' => 'Transportes Terrestres', 'objective' => 'Regular e desenvolver os transportes terrestres e a circulação rodoviária.'],
            ['oge_code' => '003', 'name' => 'Transportes Marítimos e Cabotagem', 'objective' => 'Regular os transportes marítimos, as atividades portuárias e a cabotagem.'],
            ['oge_code' => '004', 'name' => 'Comunicações e Serviços Postais', 'objective' => 'Desenvolver as infraestruturas de comunicações e assegurar o serviço postal universal.'],
            ['oge_code' => '005', 'name' => 'Meteorologia e Geofísica', 'objective' => 'Assegurar a vigilância meteorológica, climatológica e sismológica nacional.'],
            ['oge_code' => '006', 'name' => 'Digitalização e Segurança Cibernética', 'objective' => 'Promover a transformação digital e a proteção cibernética.'],
            ['oge_code' => '007', 'name' => 'Segurança e Investigação de Acidentes', 'objective' => 'Prevenir e investigar acidentes e incidentes aéreos, marítimos e terrestres.'],
        ];

        foreach ($programs as $prog) {
            // updateOrCreate: pre-existing placeholder rows (001-004) are renamed in place.
            Program::updateOrCreate(['oge_code' => $prog['oge_code']], $prog);
        }
    }
}
