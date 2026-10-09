<?php

namespace Database\Seeders\Samples;

use App\Models\Program;
use App\Services\SampleRegistry;
use Illuminate\Database\Seeder;

/**
 * Working programmes for the Ministério dos Transportes e Comunicações,
 * derived from the sectors in Decreto-Lei 75/2023 as amended by DL 12/2026.
 *
 * IMPORTANT: the codes (MTC-001 …) are PLACEHOLDERS, deliberately prefixed so they
 * cannot collide with real programme codes. They are not the Ministry of Finance's
 * official OGE codes. Real planning needs your own programmes with official codes.
 */
class MtcProgramSeeder extends Seeder
{
    public function run(SampleRegistry $registry): void
    {
        $programs = [
            ['oge_code' => 'MTC-001', 'name' => 'Administração e Gestão Institucional', 'objective' => 'Garantir o funcionamento administrativo, financeiro e de recursos humanos do ministério.'],
            ['oge_code' => 'MTC-002', 'name' => 'Transportes Terrestres', 'objective' => 'Regular e desenvolver os transportes terrestres e a circulação rodoviária.'],
            ['oge_code' => 'MTC-003', 'name' => 'Transportes Marítimos e Cabotagem', 'objective' => 'Regular os transportes marítimos, as atividades portuárias e a cabotagem.'],
            ['oge_code' => 'MTC-004', 'name' => 'Comunicações e Serviços Postais', 'objective' => 'Desenvolver as infraestruturas de comunicações e assegurar o serviço postal universal.'],
            ['oge_code' => 'MTC-005', 'name' => 'Meteorologia e Geofísica', 'objective' => 'Assegurar a vigilância meteorológica, climatológica e sismológica nacional.'],
            ['oge_code' => 'MTC-006', 'name' => 'Digitalização e Segurança Cibernética', 'objective' => 'Promover a transformação digital e a proteção cibernética.'],
            ['oge_code' => 'MTC-007', 'name' => 'Segurança e Investigação de Acidentes', 'objective' => 'Prevenir e investigar acidentes e incidentes aéreos, marítimos e terrestres.'],
        ];

        foreach ($programs as $prog) {
            // Programmes loaded before the registry existed used plain codes (001 …): when
            // adopting them, give them the prefixed code first so they are recognised.
            if ($registry->adopt) {
                Program::where('name', $prog['name'])->where('oge_code', substr($prog['oge_code'], 4))
                    ->update(['oge_code' => $prog['oge_code']]);
            }

            $registry->upsert('mtc', Program::class, ['oge_code' => $prog['oge_code']], $prog);
        }
    }
}
