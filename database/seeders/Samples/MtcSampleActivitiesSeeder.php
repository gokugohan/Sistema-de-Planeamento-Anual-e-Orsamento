<?php

namespace Database\Seeders\Samples;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Services\SampleRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ILLUSTRATIVE sample activities for the MTC, for demos and training.
 *
 * These are NOT MTC's planned activities. Each one is loosely based on the
 * mandate the organic law (DL 75/2023 as amended by DL 12/2026) gives the
 * service; the amounts, targets and dates are invented placeholders.
 *
 * Every sample is recognisable (code starts with SAMPLE-, description starts
 * with "[EXEMPLO]"), and every record is registered so Management > Sample Data
 * (or `php artisan paa:purge-samples`) removes exactly what was created.
 */
class MtcSampleActivitiesSeeder extends Seeder
{
    public const CODE_PREFIX = 'SAMPLE-';

    public const DESCRIPTION_PREFIX = '[EXEMPLO] ';

    private const YEAR = 2027;

    public function run(SampleRegistry $registry): void
    {
        $programs = Program::pluck('id', 'oge_code');
        $directorates = Directorate::pluck('id', 'code');
        $classes = EconomicClassification::pluck('id', 'code');

        DB::transaction(function () use ($programs, $directorates, $classes, $registry) {
            foreach ($this->samples() as $i => $s) {
                [$dir, $prog, $desc, $quarter, $priority, $procurement, $indicators, $lines] = $s;

                $prog = 'MTC-'.$prog;

                if (! isset($directorates[$dir], $programs[$prog])) {
                    $this->command?->warn("Skipping sample for {$dir}: directorate or programme {$prog} missing.");

                    continue;
                }

                $code = self::CODE_PREFIX.$dir.'-'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
                $start = sprintf('%d-%02d-01', self::YEAR, ($quarter - 1) * 3 + 1);
                $end = date('Y-m-t', strtotime(sprintf('%d-%02d-01', self::YEAR, $quarter * 3)));

                $activity = $registry->upsert('mtc', Activity::class, ['code' => $code], [
                    'fiscal_year' => self::YEAR,
                    'program_id' => $programs[$prog],
                    'directorate_id' => $directorates[$dir],
                    'description' => self::DESCRIPTION_PREFIX.$desc,
                    'quarter' => $quarter,
                    'priority' => $priority,
                    'planned_start' => $start,
                    'planned_end' => $end,
                    'procurement_required' => $procurement,
                    'status' => Activity::STATUS_DRAFT,
                ]);

                if (! $registry->isRegistered($activity)) {
                    continue; // a real activity with this code already exists: leave it alone
                }

                $activity->indicators()->delete();
                foreach ($indicators as [$name, $type, $unit, $target]) {
                    Indicator::create([
                        'activity_id' => $activity->id,
                        'name' => $name,
                        'type' => $type,
                        'unit' => $unit,
                        'annual_target' => $target,
                    ]);
                }

                $activity->lineItems()->delete();
                foreach ($lines as $n => [$classCode, $amount]) {
                    LineItem::create([
                        'activity_id' => $activity->id,
                        'code' => $code.'-L'.($n + 1),
                        'economic_classification_id' => $classes[$classCode],
                        'estimated_amount' => $amount,
                    ]);
                }
            }
        });
    }

    /**
     * [directorate, programme, description, quarter, priority, procurement,
     *  [[indicator, Output|Outcome, unit, target]...], [[class code, amount]...]]
     */
    private function samples(): array
    {
        return [
            ['DNTT', '002', 'Atualização do quadro regulamentar do transporte público de passageiros', 2, 'Alta', false,
                [['Diplomas regulamentares elaborados', 'Output', 'diplomas', 3]],
                [['20', 18000], ['10', 12000]]],
            ['DNTT', '002', 'Campanha nacional de segurança rodoviária', 3, 'Média', true,
                [['Municípios abrangidos', 'Output', 'municípios', 12]],
                [['20', 45000], ['40', 8000]]],
            ['DNTM', '003', 'Revisão do regime de licenciamento de embarcações', 1, 'Alta', false,
                [['Processos de licenciamento revistos', 'Output', 'processos', 40]],
                [['20', 15000]]],
            ['DNC', '003', 'Levantamento das rotas e operadores de cabotagem', 2, 'Média', true,
                [['Rotas mapeadas', 'Output', 'rotas', 8]],
                [['20', 22000], ['40', 6000]]],
            ['DNMG', '005', 'Manutenção da rede de estações meteorológicas', 1, 'Alta', true,
                [['Estações operacionais', 'Outcome', 'estações', 20]],
                [['20', 35000], ['40', 25000]]],
            ['DNMG', '005', 'Modernização do sistema de monitorização sísmica', 3, 'Média', true,
                [['Sensores instalados', 'Output', 'sensores', 6]],
                [['50', 120000]]],
            ['DNSP', '004', 'Melhoria da cobertura do serviço postal universal', 2, 'Média', false,
                [['Postos de atendimento avaliados', 'Output', 'postos', 13]],
                [['20', 14000], ['30', 5000]]],
            ['DNIC', '004', 'Reforço da rede de comunicações das instituições do Governo', 3, 'Alta', true,
                [['Instituições ligadas à rede', 'Output', 'instituições', 15]],
                [['50', 150000], ['20', 10000]]],
            ['DNALP', '001', 'Manutenção e conservação das instalações do ministério', 1, 'Média', true,
                [['Instalações intervencionadas', 'Output', 'instalações', 4]],
                [['20', 30000], ['40', 12000]]],
            ['DNALP', '001', 'Inventário geral do património do ministério', 4, 'Baixa', false,
                [['Bens inventariados', 'Output', 'bens', 1500]],
                [['20', 6000]]],
            ['DNOGF', '001', 'Preparação e consolidação da proposta orçamental anual', 3, 'Alta', false,
                [['Proposta orçamental submetida no prazo', 'Outcome', 'proposta', 1]],
                [['20', 4000]]],
            ['DNA', '001', 'Planeamento e execução dos procedimentos de aprovisionamento do ano', 1, 'Média', false,
                [['Contratos celebrados', 'Output', 'contratos', 25]],
                [['20', 7000]]],
            ['DNRH', '001', 'Plano de formação contínua do pessoal', 2, 'Média', true,
                [['Funcionários formados', 'Output', 'funcionários', 80]],
                [['20', 28000]]],
            ['GIGTC', '001', 'Programa anual de inspeção e auditoria interna', 2, 'Alta', false,
                [['Inspeções concluídas', 'Output', 'inspeções', 10]],
                [['20', 16000], ['10', 4000]]],
            ['GPPC', '001', 'Elaboração do Plano Anual de Atividades e relatórios trimestrais', 1, 'Alta', false,
                [['Relatórios trimestrais produzidos', 'Output', 'relatórios', 4]],
                [['20', 5000]]],
            ['GJ', '001', 'Apoio jurídico à produção de atos normativos do ministério', 2, 'Média', false,
                [['Pareceres e minutas emitidos', 'Output', 'documentos', 30]],
                [['20', 3500]]],
            ['GPIAIAMT', '007', 'Formação de investigadores de acidentes e incidentes', 3, 'Alta', true,
                [['Investigadores formados', 'Output', 'pessoas', 8]],
                [['20', 40000]]],
            ['GDSC', '006', 'Plano de segurança da informação do ministério', 2, 'Alta', true,
                [['Sistemas avaliados', 'Output', 'sistemas', 6]],
                [['20', 20000], ['40', 15000]]],
            ['UDIG', '006', 'Digitalização de serviços prestados ao público', 3, 'Média', true,
                [['Serviços digitalizados', 'Output', 'serviços', 3]],
                [['50', 60000]]],
            // ── Indirect administration: each body plans its own activities and funds them from
            //    its own budget (art. 4(3) of the organic law); the amounts are placeholders.
            ['ANATL', '008', 'Manutenção das infraestruturas e dos equipamentos de navegação aérea', 1, 'Alta', true,
                [['Equipamentos de navegação em condições operacionais', 'Outcome', '%', 95]],
                [['20', 80000], ['40', 40000]]],
            ['APORTIL', '003', 'Conservação e segurança das instalações portuárias', 2, 'Alta', true,
                [['Instalações portuárias intervencionadas', 'Output', 'instalações', 5]],
                [['20', 60000], ['50', 200000]]],
            ['AACTL', '008', 'Programa anual de supervisão e inspeção da segurança da aviação civil', 2, 'Alta', false,
                [['Inspeções realizadas', 'Output', 'inspeções', 24]],
                [['20', 25000], ['10', 15000]]],
            ['ANC', '004', 'Monitorização do mercado das telecomunicações e gestão do espectro radioelétrico', 3, 'Média', true,
                [['Relatórios de monitorização publicados', 'Output', 'relatórios', 4]],
                [['20', 30000], ['40', 20000]]],
            ['TICTIMOR', '006', 'Manutenção e expansão da rede informática do Governo', 1, 'Alta', true,
                [['Instituições ligadas à rede do Governo', 'Output', 'instituições', 30]],
                [['20', 70000], ['50', 90000]]],
        ];
    }
}
