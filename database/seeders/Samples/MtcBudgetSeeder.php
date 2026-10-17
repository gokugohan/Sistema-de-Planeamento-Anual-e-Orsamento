<?php

namespace Database\Seeders\Samples;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Models\Subprogram;
use App\Models\User;
use App\Services\ActivityVersions;
use App\Services\SampleRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The real annual plans of the Ministério dos Transportes e Comunicações (MTC) and of the public
 * bodies of its indirect administration, for 2025, 2026 and 2027, from the State Budget (OGE).
 *
 * Source: Livro Orçamental, Volume II, "Planos Anuais dos Serviços e Entidades do Setor Público
 * Administrativo" (Anexo K), read from the published PDFs and cross-checked against the budget law:
 *   2025  Lei n.º 8/2024, de 26 de novembro. MTC 13 353 528 USD.
 *   2026  Lei n.º 8/2025, de 27 de novembro, as amended by Lei n.º 8/2026, de 8 de junho.
 *         MTC 12 800 471 USD, reduced to 12 674 262 USD by the amendment.
 *   2027  Proposta de Lei do OGE 2027, delivered to Parliament on 1 October 2026 and still being
 *         debated: the plan is loaded, but no spending is reported against it yet.
 * The totals of every programme and category match the budget tables; see data/README.md.
 *
 * What is loaded per year: each activity with its official code, name, programme, sub-programme,
 * owning division, indicator (with starting point and target as printed) and the amount in each
 * economic category. Where the official plan prints no amount for an activity, it is not loaded.
 * The OGE lists the autonomous bodies (APORTIL, TIC Timor, AACTL, ANC) as entities of their own; here
 * they are part of one MTC package, as the ministry plans together with them. Approved activities
 * become version 1 of the plan (see ActivityVersions).
 *
 * Two things are NOT in the published plans and are therefore not invented: the quarter in which an
 * activity runs (every activity is an annual one) and its priority.
 */
class MtcBudgetSeeder extends Seeder
{
    public const YEARS = [2025, 2026, 2027];

    /** @var array<int, array<int, int>> ids of the activities this run planted, by year (their execution is sampled afterwards) */
    private array $planted = [];

    private const CATEGORY = ['10' => 'SV', '20' => 'BS', '30' => 'TP', '40' => 'CM', '50' => 'CD'];

    /**
     * @param  array<int, string>|null  $units  unit codes: load only the activities these units own (one office), null for the whole ministry
     * @param  bool  $illustrative  also load the illustrative plan of the Gabinete de Digitalização e Segurança Cibernética
     */
    public function run(SampleRegistry $registry, ?array $units = null, bool $illustrative = false): void
    {
        foreach (self::YEARS as $year) {
            $data = self::data($year, $units, $illustrative);

            if ($units !== null && ! $data['entities']) {
                continue; // the office has no activities this year
            }

            if ($this->alreadyLoaded($registry, $year, $data)) {
                continue; // a closed year cannot be edited; purge the sample to load it again
            }

            DB::transaction(function () use ($registry, $year, $data) {
                $this->loadActivities($registry, $year, $data);
                $this->approveVersions($year);
                // Spending and progress up to today, only for the years whose budget law has been applied
                if (isset($data['envelope'])) {
                    (new MtcExecutionSampler)->seed($year, $this->planted[$year] ?? []);
                }
            });
        }
    }

    /**
     * The year's plan, narrowed to the activities owned by $units when given. Illustrative activities
     * (data/gdsc_illustrative.json) are written in the same shape and are marked "illustrative".
     */
    public static function data(int $year, ?array $units = null, bool $illustrative = false): array
    {
        $data = json_decode(file_get_contents(__DIR__."/data/mtc_oge_{$year}.json"), true, 512, JSON_THROW_ON_ERROR);

        if ($illustrative) {
            $extra = json_decode(file_get_contents(__DIR__.'/data/gdsc_illustrative.json'), true, 512, JSON_THROW_ON_ERROR)[(string) $year] ?? [];
            $data['entities']['025']['activities'] = array_merge($data['entities']['025']['activities'], $extra);
        }

        if ($units !== null) {
            foreach ($data['entities'] as $entity => $e) {
                $data['entities'][$entity]['activities'] = array_values(array_filter(
                    $e['activities'],
                    fn ($a) => $a['amounts'] && in_array(MtcCostCentres::ownerUnit($entity, $a['div']), $units, true)
                ));
            }
            $data['entities'] = array_filter($data['entities'], fn ($e) => $e['activities']);
            $data['amendments'] = []; // ministry-wide amendments do not apply to one office
        }

        return $data;
    }

    /** OGE codes of the programmes the given units' activities use. */
    public static function programmeCodes(array $units, bool $illustrative = false): array
    {
        $codes = [];
        foreach (self::YEARS as $year) {
            foreach (self::data($year, $units, $illustrative)['entities'] as $e) {
                foreach ($e['activities'] as $a) {
                    $codes[$a['programme']] = true;
                }
            }
        }

        return array_map('strval', array_keys($codes)); // numeric codes such as 510 become integer keys
    }

    /** @return array<string, array<string, float>> category totals of the plan, per entity */
    private function loadActivities(SampleRegistry $registry, int $year, array $data): array
    {
        $classes = EconomicClassification::pluck('id', 'code');
        $programs = Program::forYear($year)->pluck('id', 'oge_code');
        $subprogrammes = [];
        $units = Directorate::pluck('id', 'code');
        $totals = [];

        foreach ($data['entities'] as $entity => $e) {
            foreach ($e['activities'] as $a) {
                if (! $a['amounts']) {
                    continue; // listed in the official plan without any amount: nothing to plan or fund
                }

                $unit = MtcCostCentres::ownerUnit($entity, $a['div']);
                if (! isset($units[$unit], $programs[$a['programme']])) {
                    $this->command?->warn("Skipping {$year} {$a['code']}: unit {$unit} or programme {$a['programme']} missing.");

                    continue;
                }

                // the subprogramme the budget book files the activity under (the programme's, once per code)
                $subprogramId = null;
                if (($a['subprogramme'] ?? '') !== '') {
                    $key = $programs[$a['programme']].'|'.$a['subprogramme'];
                    $subprogrammes[$key] ??= $registry->upsert('mtc', Subprogram::class,
                        ['program_id' => $programs[$a['programme']], 'code' => (string) $a['subprogramme']],
                        ['name' => Str::limit(($a['subprogramme_name'] ?? '') !== '' ? $a['subprogramme_name'] : (string) $a['subprogramme'], 250, '')])->id;
                    $subprogramId = $subprogrammes[$key];
                }

                $activity = $registry->upsert('mtc', Activity::class,
                    ['fiscal_year' => $year, 'code' => $a['code'], 'directorate_id' => $units[$unit]], [
                        'program_id' => $programs[$a['programme']],
                        'subprogram_id' => $subprogramId,
                        'description' => Str::limit($a['name'], 990, ''),
                        'objective' => ($a['illustrative'] ?? false)
                            ? 'Atividade ilustrativa do exemplo; os valores não provêm de nenhum plano ou lei orçamental publicados.'
                            : ($a['subprogramme_name'] ? Str::limit("Subprograma {$a['subprogramme']}: {$a['subprogramme_name']}", 1900, '') : null),
                        'priority' => 'Média',
                        'quarter' => 1,
                        'execution_quarters' => [1, 2, 3, 4],
                        'period_type' => 'annual',
                        'planned_start' => "{$year}-01-01",
                        'planned_end' => "{$year}-12-31",
                        'procurement_required' => false,
                        'gender_responsive' => false,
                        'status' => $a['status'] ?? Activity::STATUS_APPROVED,
                        'is_test_data' => false,
                    ] + $this->workflowDates($year, $a['status'] ?? null));

                if (! $registry->isRegistered($activity)) {
                    continue; // a real activity with this code already exists: leave it alone
                }

                $this->planted[$year][] = $activity->id;
                $activity->lineItems()->delete();
                foreach ($a['amounts'] as $category => $amount) {
                    LineItem::create([
                        'activity_id' => $activity->id,
                        'code' => $a['code'].'-'.$category,
                        'economic_classification_id' => $classes[$category],
                        'funding_source_id' => $this->fundingSourceId($a['funding'] ?? null),
                        'account_code' => $category,
                        'estimated_amount' => $amount,
                        'is_test_data' => false,
                    ]);
                    $totals[$entity][$category] = ($totals[$entity][$category] ?? 0) + $amount;
                }

                $activity->indicators()->delete();
                if ($a['indicator'] !== '') {
                    $text = $a['indicator'].' '.$a['target'];
                    $indicator = Indicator::create([
                        'activity_id' => $activity->id,
                        'name' => Str::limit($a['indicator'], 250, ''),
                        'type' => 'Output',
                        'unit' => str_contains($text, '%') ? '%' : 'unidades',
                        'measure' => $a['measure'] ?? (str_contains($text, '%') ? Indicator::LEVEL : Indicator::CUMULATIVE),
                        'means_of_verification' => $a['means'] ?? null,
                        'due_quarters' => $a['quarters'] ?? null,
                        'annual_target' => $this->number($a['target']),
                        'baseline' => $a['baseline'] !== '' ? Str::limit($a['baseline'], 495, '') : null,
                        'target_text' => $a['target'] !== '' ? Str::limit($a['target'], 995, '') : null,
                    ]);
                    $this->planImplementation($indicator, $a['quarters'] ?? [1, 2, 3, 4]);
                    $activity->lineItems()->update(['indicator_id' => $indicator->id]); // the one indicator carries the whole budget
                }
            }
        }

        return $totals;
    }

    /**
     * Every approved sample activity becomes version 1 of the plan, approved by whoever approved it (or the first
     * administrator), exactly as an approval in the application does it: its amounts are then in force.
     */
    private function approveVersions(int $year): void
    {
        $versions = app(ActivityVersions::class);
        $fallback = User::role('Admin')->first() ?? User::first();
        $activities = Activity::withoutGlobalScopes()->whereIn('id', $this->planted[$year] ?? [])
            ->where('status', Activity::STATUS_APPROVED)->where('version', 0)->get();
        foreach ($activities as $activity) {
            $by = $activity->approved_by ? User::find($activity->approved_by) : null;
            $versions->approved($activity, $by ?? $fallback);
        }
    }

    /** Timestamps matching a workflow step, for the illustrative activities that sit mid-workflow. */
    private function workflowDates(int $year, ?string $status): array
    {
        if ($status === null) {
            return [];
        }

        return [
            'submitted_at' => $status === Activity::STATUS_DRAFT ? null : "{$year}-01-15 09:00:00",
            'reviewed_at' => in_array($status, [Activity::STATUS_DRAFT, Activity::STATUS_SUBMITTED], true) ? null : "{$year}-01-22 09:00:00",
            'approved_at' => $status === Activity::STATUS_APPROVED ? "{$year}-02-05 09:00:00" : null,
        ];
    }

    /** A year already loaded (and possibly closed, hence not editable) is left as it is. */
    private function alreadyLoaded(SampleRegistry $registry, int $year, array $data): bool
    {
        $first = collect($data['entities'])->flatMap(fn ($e) => $e['activities'])->first(fn ($a) => $a['amounts']);
        $existing = $first ? Activity::withoutGlobalScopes()->where('fiscal_year', $year)->where('code', $first['code'])->first() : null;

        return $existing && $registry->isRegistered($existing);
    }

    /** The first number in a target such as "a) 35,500; b) 65 000" or "100%" (thousand separators allowed). */
    private function number(string $text): float
    {
        if (! preg_match('/\d[\d.,]*/', $text, $m)) {
            return 0.0;
        }
        $n = rtrim($m[0], '.,');

        return preg_match('/^\d{1,3}([.,]\d{3})+$/', $n)
            ? (float) str_replace([',', '.'], '', $n)
            : (float) str_replace(',', '.', $n);
    }

    /**
     * An illustrative implementation plan for the sample indicator: three numbered actions with their months, inside the
     * quarters it is reported in. Not taken from any plan: it shows how the form reads.
     */
    private function planImplementation(Indicator $indicator, array $quarters): void
    {
        $months = collect($quarters)->flatMap(fn ($q) => range($q * 3 - 2, $q * 3))->values();
        $actions = [
            ['Preparar e planear o trabalho', $months->take(min(2, $months->count()))->all()],
            ['Executar as ações previstas', $months->all()],
            ['Rever e reportar os resultados', collect($quarters)->map(fn ($q) => $q * 3)->all()],
        ];
        foreach ($actions as $n => [$text, $m]) {
            $indicator->actions()->create(['position' => $n + 1, 'text' => $text, 'months' => $m]);
        }
    }

    /** The funding source a sample line takes from the wording of the illustrative data. */
    private function fundingSourceId(?string $text): ?int
    {
        $text = mb_strtolower((string) $text);
        $code = str_contains($text, 'parceiro') || str_contains($text, 'partner') ? 'PARTNER' : (str_contains($text, 'própri') ? 'OWN' : null);

        return ($code ? \App\Models\FundingSource::where('code', $code)->value('id') : null) ?? \App\Models\FundingSource::defaultId();
    }
}
