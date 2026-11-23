<?php

namespace Database\Seeders\Samples;

use App\Models\BudgetDecision;
use App\Models\BudgetDistribution;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Services\PlanningWindow;
use App\Services\SampleRegistry;
use App\Support\PlanOptions;
use Illuminate\Database\Seeder;

/**
 * The budget decisions of the sample ministry, and their distribution among its units, only as the State Budget books
 * print them; nothing is invented. For the ministry's own budget title (entity 025; the autonomous bodies have titles of
 * their own and are left out):
 *   2026  the State Budget law (Lei n.º 8/2025, de 27 de novembro), shared among the units as the budget book prints the
 *         plan of each unit; then the supplementary budget (Lei n.º 8/2026, de 8 de junho), which cut Goods and Services
 *         without saying where: it is recorded, and not distributed, so the sample shows a newer decision to share.
 *   2027  the Government's proposal to Parliament (1 October 2026), shared in the same way.
 * No budget ceiling (circular) is loaded: its figures are not published. Shares are recorded as approved, with the budget
 * book as their source, because no internal despacho is published either. The rules that show these pages stay as the
 * administrator set them.
 */
class MtcBudgetDecisionSeeder extends Seeder
{
    /** The ministry's own budget title in the sample's books. */
    public const ENTITY = '025';

    /** @var array<int, array{stage: string, date: string, reference: string, book: string}> */
    private const DECISIONS = [
        2026 => ['stage' => 'law', 'date' => '2025-11-27', 'reference' => 'Lei n.º 8/2025, de 27 de novembro', 'book' => 'Livro Orçamental 2026, Volume II (Anexo K)'],
        2027 => ['stage' => 'proposal', 'date' => '2026-10-01', 'reference' => 'Proposta de Lei do OGE 2027', 'book' => 'Livro Orçamental 2027 (proposta), Volume II (Anexo K)'],
    ];

    /** @param  array<int, int|string>|null  $years  the years of the sample being loaded (null: all of them) */
    public function run(SampleRegistry $registry, ?array $years = null): void
    {
        $classes = EconomicClassification::pluck('id', 'code');
        $units = Directorate::pluck('id', 'code');
        $window = app(PlanningWindow::class);
        PlanningWindow::forgetTree();
        $salaries = PlanOptions::SALARIES_CENTRAL ? (int) ($classes['10'] ?? 0) : 0;

        foreach (self::DECISIONS as $year => $d) {
            if (($years !== null && ! in_array($year, array_map('intval', $years), true)) || BudgetDecision::where('fiscal_year', $year)->exists()) {
                continue; // not asked for, or the year already has decisions of its own
            }
            $data = MtcBudgetSeeder::data($year);
            $entity = $data['entities'][self::ENTITY] ?? null;
            if (! $entity) {
                continue;
            }

            // the plan of each top-level unit by category, as the book prints it
            $byClass = [];
            $byUnit = [];
            foreach ($entity['activities'] as $a) {
                $code = MtcCostCentres::ownerUnit(self::ENTITY, $a['div']);
                if (! isset($units[$code])) {
                    continue;
                }
                $root = $window->rootOf((int) $units[$code]);
                foreach ($a['amounts'] ?? [] as $category => $amount) {
                    $class = (int) ($classes[(string) $category] ?? 0);
                    if ($class) {
                        $byClass[$class] = ($byClass[$class] ?? 0) + (float) $amount;
                        $byUnit[$root][$class] = ($byUnit[$root][$class] ?? 0) + (float) $amount;
                    }
                }
            }
            if (! $byClass) {
                continue;
            }

            $decision = $this->decision($year, $d['stage'], $d['date'], $d['reference'], $byClass, $byClass, $d['book']);
            $registry->register('mtc', $decision);

            $distribution = BudgetDistribution::create([
                'budget_decision_id' => $decision->id, 'fiscal_year' => $year, 'version' => 1, 'status' => BudgetDistribution::APPROVED,
                'act_reference' => $d['book'], 'act_date' => $d['date'], 'approved_at' => $d['date'].' 09:00:00',
                'note' => 'Partes de cada unidade tal como o livro do Orçamento Geral do Estado as imprime; não é publicado nenhum despacho interno.',
            ]);
            $lines = [];
            foreach ($byClass as $class => $total) {
                $given = 0.0;
                foreach ($byUnit as $root => $amounts) {
                    if ($class !== $salaries && ($amounts[$class] ?? 0) > 0) {
                        $lines[] = ['directorate_id' => $root, 'economic_classification_id' => $class, 'amount' => round($amounts[$class], 2)];
                        $given += $amounts[$class];
                    }
                }
                if (abs($total - $given) >= 0.005) {
                    $lines[] = ['directorate_id' => null, 'economic_classification_id' => $class, 'amount' => round($total - $given, 2)];
                }
            }
            $distribution->lines()->createMany($lines);

            // 2026: the supplementary budget changed the total by category, without saying which units; not distributed
            $amendment = $data['amendments'][self::ENTITY] ?? null;
            if ($amendment) {
                $amounts = [];
                foreach ($amendment['amounts'] as $category => $amount) {
                    if ($class = (int) ($classes[(string) $category] ?? 0)) {
                        $amounts[$class] = (float) $amount;
                    }
                }
                $registry->register('mtc', $this->decision($year, 'supplementary', $amendment['date'], $amendment['law'], $amounts, $byClass, $amendment['note'] ?? null));
            }
        }
    }

    /** @param  array<int, float>  $amounts  @param  array<int, float>  $plan */
    private function decision(int $year, string $stage, string $date, string $reference, array $amounts, array $plan, ?string $note): BudgetDecision
    {
        $amounts = array_filter($amounts, fn ($v) => $v > 0);

        return BudgetDecision::create([
            'fiscal_year' => $year, 'stage' => $stage, 'decided_on' => $date, 'reference' => mb_substr($reference, 0, 255), 'note' => $note,
            'amounts' => array_combine(array_map('strval', array_keys($amounts)), array_map(fn ($v) => round($v, 2), $amounts)),
            'total' => round(array_sum($amounts), 2),
            'plan_amounts' => array_combine(array_map('strval', array_keys($plan)), array_map(fn ($v) => round($v, 2), $plan)),
            'plan_total' => round(array_sum($plan), 2),
        ]);
    }
}
