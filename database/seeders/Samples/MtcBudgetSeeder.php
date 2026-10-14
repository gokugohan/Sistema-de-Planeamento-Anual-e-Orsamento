<?php

namespace Database\Seeders\Samples;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\FiscalEnvelopeCategoryAllocation;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use App\Services\BudgetCycleService;
use App\Services\EnvelopeDistributor;
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
 *         debated: the plan is loaded, but there is no budget law and therefore no envelope.
 * The totals of every programme and category match the budget tables; see data/README.md.
 *
 * What is loaded per year: each activity with its official code, name, programme, sub-programme,
 * owning division, indicator (with starting point and target as printed) and the amount in each
 * economic category. Where the official plan prints no amount for an activity, it is not loaded.
 * The OGE lists the autonomous bodies (APORTIL, TIC Timor, AACTL, ANC) as entities of their own; here
 * they are part of one MTC package, as the ministry plans and receives its envelope together with them.
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

            // A year that already has a real envelope belongs to real data: never plan or distribute over it.
            if ($this->hasRealEnvelope($registry, $year)) {
                $registry->skipped[] = "Fiscal year {$year}: a real fiscal envelope already exists, so no sample plan was loaded for it";

                continue;
            }

            DB::transaction(function () use ($registry, $year, $data) {
                $totals = $this->loadActivities($registry, $year, $data);
                $this->registerBudget($registry, $year, $data, $totals);
                // Spending and progress up to today, only where the budget law has been applied
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
        $programs = Program::pluck('id', 'oge_code');
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

                $activity = $registry->upsert('mtc', Activity::class,
                    ['fiscal_year' => $year, 'code' => $a['code'], 'directorate_id' => $units[$unit]], [
                        'program_id' => $programs[$a['programme']],
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
                        'account_code' => $category,
                        'estimated_amount' => $amount,
                        'is_test_data' => false,
                    ]);
                    $totals[$entity][$category] = ($totals[$entity][$category] ?? 0) + $amount;
                }

                $activity->indicators()->delete();
                if ($a['indicator'] !== '') {
                    $text = $a['indicator'].' '.$a['target'];
                    Indicator::create([
                        'activity_id' => $activity->id,
                        'name' => Str::limit($a['indicator'], 250, ''),
                        'type' => 'Output',
                        'unit' => str_contains($text, '%') ? '%' : 'unidades',
                        'annual_target' => $this->number($a['target']),
                        'baseline' => $a['baseline'] !== '' ? Str::limit($a['baseline'], 495, '') : null,
                        'target_text' => $a['target'] !== '' ? Str::limit($a['target'], 995, '') : null,
                    ]);
                }
            }
        }

        return $totals;
    }

    /** The envelope (budget law approved) or the budget cycle (bill still in Parliament) of the year. */
    private function registerBudget(SampleRegistry $registry, int $year, array $data, array $totals): void
    {
        // The package: the ministry and its autonomous bodies, with any amendment replacing the ministry's figures.
        $package = [];
        foreach ($totals as $entity => $byCategory) {
            $amounts = $data['amendments'][$entity]['amounts'] ?? $byCategory;
            foreach ($amounts as $category => $amount) {
                $package[$category] = ($package[$category] ?? 0) + $amount;
            }
        }

        if (isset($data['envelope'])) {
            $meta = $data['envelope'];
            $envelope = $registry->upsert('mtc', FiscalEnvelope::class, ['fiscal_year' => $year], [
                'reference_number' => $meta['reference'],
                'source_authority' => 'Parlamento Nacional',
                'total_amount' => array_sum($package),
                'status' => FiscalEnvelope::STATUS_APPROVED,
                'approved_date' => $meta['date'],
                'promulgation_date' => $meta['date'],
                'start_date' => "{$year}-01-01",
                'due_date' => "{$year}-12-31",
                'budget_law_reference' => $meta['law'],
                'is_test_data' => false,
            ]);

            $classes = EconomicClassification::pluck('id', 'code');
            foreach (array_keys(self::CATEGORY) as $category) {
                FiscalEnvelopeCategoryAllocation::updateOrCreate(
                    ['fiscal_envelope_id' => $envelope->id, 'economic_classification_id' => $classes[$category]],
                    ['allocated_amount' => $package[$category] ?? 0]
                );
            }

            // The plan is distributed over the envelope exactly as the application does it.
            app(EnvelopeDistributor::class)->distribute($envelope);

            if (($meta['status'] ?? null) === FiscalEnvelope::STATUS_CLOSED) {
                $envelope->update(['status' => FiscalEnvelope::STATUS_CLOSED]);
            }
        } elseif (isset($data['cycle'])) {
            $meta = $data['cycle'];
            $service = app(BudgetCycleService::class);
            $service->recordSubmission($year);
            $cycle = $service->recordCroDecision($year, array_sum($package), $meta['date'], $meta['reference'], $meta['notes']);
            $cycle->update(['submitted_at' => $meta['date'].' 00:00:00']);
            $registry->register('mtc', $cycle);
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

    /** Is there a fiscal envelope for the year that the sample did not create? */
    private function hasRealEnvelope(SampleRegistry $registry, int $year): bool
    {
        $envelope = FiscalEnvelope::withoutGlobalScopes()->where('fiscal_year', $year)->first();

        return $envelope !== null && ! $registry->isRegistered($envelope);
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
}
