<?php

namespace Database\Seeders\Samples;

use App\Models\Program;
use App\Services\SampleRegistry;
use Illuminate\Database\Seeder;

/**
 * The programmes of the Ministério dos Transportes e Comunicações and its autonomous bodies in the
 * State Budget (OGE), with the official programme codes and the medium-term objective printed in the
 * annual plans (Livro Orçamental, Volume II, Anexo K).
 *
 * These are the Ministry of Finance's real codes. A programme with the same code that already exists
 * (programmes such as 510 are shared by every ministry) is never modified; the sample then simply
 * uses it, and purging leaves it alone.
 */
class MtcProgramSeeder extends Seeder
{
    /**
     * The national objectives each programme contributes to. ILLUSTRATIVE: a mapping by topic, not taken from
     * the budget books. [framework, reference, description].
     */
    private const ALIGNMENTS = [
        '502' => [['SDG', 'SDG 9', 'Indústria, inovação e infraestruturas'], ['PED', 'PED 2011-2030', 'Desenvolvimento de infraestruturas']],
        '510' => [['SDG', 'SDG 16', 'Instituições eficazes, responsáveis e inclusivas'], ['PED', 'PED 2011-2030', 'Enquadramento institucional']],
        '558' => [['SDG', 'SDG 9', 'Indústria, inovação e infraestruturas']],
        '980' => [['SDG', 'SDG 10', 'Reduzir as desigualdades']],
        'A08' => [['SDG', 'SDG 9', 'Acesso universal e acessível às tecnologias de informação e comunicação'], ['PED', 'PED 2011-2030', 'Desenvolvimento de infraestruturas']],
        'A38' => [['SDG', 'SDG 13', 'Ação climática']],
    ];

    /** @param  array<int, string>|null  $only  OGE codes to load (null: all six) */
    public function run(SampleRegistry $registry, ?array $only = null): void
    {
        $programs = [
            ['oge_code' => '502', 'name' => 'Transportes', 'objective' => 'Sistema de transporte terrestre em bom funcionamento que contribui a ligar as comunidades, apoiar o desenvolvimento rural, a indústria e o turismo e fornecer acesso ao mercado e apoiar os serviços públicos de fornecimento.'],
            ['oge_code' => '510', 'name' => 'Funcionamento e Desenvolvimento Institucional', 'objective' => 'Construir confiança no governo.'],
            ['oge_code' => '558', 'name' => 'Serviços Postais', 'objective' => 'Acesso aos serviços postais de forma eficiente, rápida e acessível.'],
            ['oge_code' => '980', 'name' => 'Inclusão Social', 'objective' => null],
            ['oge_code' => 'A08', 'name' => 'Tecnologia de Informação e Comunicação', 'objective' => 'Todas as instituições públicas têm acesso a infraestrutura de comunicação de qualidade em todo o país.'],
            ['oge_code' => 'A38', 'name' => 'Meteorologia e Geofísica', 'objective' => 'Cidadãos de Timor-Leste informados sobre o clima e o estado do tempo, disponível para todos os cidadãos.'],
        ];

        // Programmes belong to a fiscal year, so each one is registered for every year of the sample.
        foreach (MtcBudgetSeeder::YEARS as $year) {
            foreach ($programs as $prog) {
                if ($only !== null && ! in_array($prog['oge_code'], $only, true)) {
                    continue;
                }
                $program = $registry->upsert('mtc', Program::class, ['fiscal_year' => $year, 'oge_code' => $prog['oge_code']], $prog);
                if ($registry->isRegistered($program) && $program->alignments()->doesntExist()) {
                    foreach (self::ALIGNMENTS[$prog['oge_code']] ?? [] as [$framework, $reference, $description]) {
                        $program->alignments()->create(['framework' => $framework, 'reference' => $reference, 'description' => $description]);
                    }
                }
            }
        }
    }
}
