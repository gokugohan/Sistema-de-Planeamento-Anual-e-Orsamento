<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Optional rules of the planning cycle that an institution switches on when its own rules ask for them. Every option
 * is off by default, which is how the application behaved before they existed, except the order of the year
 * (a plan must be complete before it is approved), which is on; nothing here assumes a rule that the
 * institution has not confirmed (see docs/improvement/02-INSTITUTIONAL-REQUIREMENTS-REGISTER.md). Only a difference
 * from the defaults is stored.
 */
final class PlanOptions
{
    /** Fields that may be made mandatory before an activity is submitted, on top of the ones that always are. */
    public const FIELDS = [
        'objective' => 'Objective of the activity',
        'manager_name' => 'Person responsible',
        'location' => 'Location',
        'output_id' => 'Output it serves',
        'indicator_baseline' => 'Baseline of every indicator',
        'indicator_means' => 'Means of verification of every indicator',
    ];

    /**
     * @param  bool  $obligations  every active top-level unit must finalize a plan or declare that it has nothing to plan
     * @param  bool  $financeInstitutionWide  finance officers confirm budgets and verify spending in every unit
     * @param  array<int, string>  $requiredFields  keys of FIELDS that must be filled in before submitting
     * @param  bool  $externalReference  a revision may be marked as needing an approval outside the institution
     * @param  bool  $correctiveActions  reports that are off track or partly on track carry tracked corrective actions
     * @param  bool  $baselines  the approved plan of a year can be recorded as a baseline and printed as it stood then
     * @param  bool  $structureAtApproval  reports show each activity under the unit it belonged to when it was approved
     * @param  bool  $completeBeforeApproval  a directorate's activities are decided on, and its plan finalized, only once all of them have been submitted
     * @param  bool  $budgetDecisions  the decisions on the institution's budget (committee, budget law, supplementary budget) are recorded by category and compared with the plan
     * @param  bool  $budgetDistribution  a budget decision is shared among the top-level units by category (prepared by the budget unit, approved by the head of the institution), and each unit's plan is compared with its share; implies $budgetDecisions
     * @param  string  $ceilingMode  what a share does when the head approves the plan: off (shown only), warn (approval needs a remark when over) or block (refused when over); see CEILING_MODES
     * @param  int|null  $budgetUnitId  the top-level unit that prepares the distribution (in a ministry, its finance and administration directorate)
     * @param  string  $distributionApprover  who approves a distribution: the head of the institution, or the head of the budget unit by delegation; see APPROVERS
     * @param  bool  $budgetChangeChecks  a revision that moves money is checked against the rules on budget changes (Decreto-Lei n.º 42/2025, art. 35) and states its effect on the targets
     *
     * Salaries and wages always stay with the institution: they are paid centrally from the payroll, and money may not
     * leave them (Lei n.º 3/2025, art. 48.3), so they are never shared among the units (SALARIES_CENTRAL). The account
     * codes a revision may not increase change every year with the execution decree: they are kept per year
     * (noIncreaseCodesFor), on the page of the year's budget decisions.
     */
    public function __construct(
        public readonly bool $obligations = false,
        public readonly bool $financeInstitutionWide = false,
        public readonly array $requiredFields = [],
        public readonly bool $externalReference = false,
        public readonly bool $correctiveActions = false,
        public readonly bool $baselines = false,
        public readonly bool $structureAtApproval = false,
        public readonly bool $completeBeforeApproval = true,
        public readonly bool $budgetDecisions = false,
        public readonly bool $budgetDistribution = false,
        public readonly string $ceilingMode = self::CEILING_WARN,
        public readonly ?int $budgetUnitId = null,
        public readonly string $distributionApprover = self::APPROVER_INSTITUTION_HEAD,
        public readonly bool $budgetChangeChecks = false,
    ) {}

    /** Salaries and wages stay with the institution, never shared among the units (fixed by law, not a choice). */
    public const SALARIES_CENTRAL = true;

    public const CEILING_OFF = 'off';

    public const CEILING_WARN = 'warn';

    public const CEILING_BLOCK = 'block';

    public const CEILING_MODES = [self::CEILING_OFF, self::CEILING_WARN, self::CEILING_BLOCK];

    public const APPROVER_INSTITUTION_HEAD = 'institution_head';

    public const APPROVER_BUDGET_UNIT_HEAD = 'budget_unit_head';

    public const APPROVERS = [self::APPROVER_INSTITUTION_HEAD, self::APPROVER_BUDGET_UNIT_HEAD];

    private const KEY = 'plan_options';

    public static function current(): self
    {
        $s = SystemSetting::get(self::KEY);
        if (! is_array($s)) {
            return new self;
        }

        return new self(
            (bool) ($s['obligations'] ?? false),
            (bool) ($s['finance_institution_wide'] ?? false),
            array_values(array_intersect(array_keys(self::FIELDS), (array) ($s['required_fields'] ?? []))),
            (bool) ($s['external_reference'] ?? false),
            (bool) ($s['corrective_actions'] ?? false),
            (bool) ($s['baselines'] ?? false),
            (bool) ($s['structure_at_approval'] ?? false),
            (bool) ($s['complete_before_approval'] ?? true),
            // sharing a decision needs the decisions: the one rule brings the other with it
            (bool) ($s['budget_decisions'] ?? false) || (bool) ($s['budget_distribution'] ?? false),
            (bool) ($s['budget_distribution'] ?? false),
            in_array($s['ceiling_mode'] ?? null, self::CEILING_MODES, true) ? $s['ceiling_mode'] : self::CEILING_WARN,
            isset($s['budget_unit_id']) && $s['budget_unit_id'] !== null ? (int) $s['budget_unit_id'] : null,
            in_array($s['distribution_approver'] ?? null, self::APPROVERS, true) ? $s['distribution_approver'] : self::APPROVER_INSTITUTION_HEAD,
            (bool) ($s['budget_change_checks'] ?? false),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'obligations' => $this->obligations,
            'finance_institution_wide' => $this->financeInstitutionWide,
            'required_fields' => array_values(array_intersect(array_keys(self::FIELDS), $this->requiredFields)),
            'external_reference' => $this->externalReference,
            'corrective_actions' => $this->correctiveActions,
            'baselines' => $this->baselines,
            'structure_at_approval' => $this->structureAtApproval,
            'complete_before_approval' => $this->completeBeforeApproval,
            'budget_decisions' => $this->budgetDecisions || $this->budgetDistribution,
            'budget_distribution' => $this->budgetDistribution,
            'ceiling_mode' => in_array($this->ceilingMode, self::CEILING_MODES, true) ? $this->ceilingMode : self::CEILING_WARN,
            'budget_unit_id' => $this->budgetUnitId,
            'distribution_approver' => in_array($this->distributionApprover, self::APPROVERS, true) ? $this->distributionApprover : self::APPROVER_INSTITUTION_HEAD,
            'budget_change_checks' => $this->budgetChangeChecks,
        ];
    }

    /** Account codes as a clean list: trimmed, without blanks or repeats. @return array<int, string> */
    public static function codes(array|string $codes): array
    {
        $list = is_string($codes) ? preg_split('/[\s,;]+/', $codes) : $codes;

        return array_values(array_unique(array_filter(array_map(fn ($c) => mb_substr(trim((string) $c), 0, 30), $list), fn ($c) => $c !== '')));
    }

    /** Store these options, or forget them when they are all off (audited either way). */
    public function save(): void
    {
        if ($this->toArray() === (new self)->toArray()) {
            SystemSetting::where('key', self::KEY)->get()->each->delete();
            SystemSetting::flushCache();

            return;
        }

        SystemSetting::set(self::KEY, $this->toArray(), 'json', 'Optional rules of the planning cycle that differ from the defaults');
    }

    // ── the guidance of a planning year (free text from the planning office, shown where activities are prepared)

    /**
     * The account codes (or their first digits) a revision may not increase in a year, as that year's budget execution
     * decree lists them (for 2026: Decreto-Lei n.º 41/2025, art. 4.3: travel, accommodation, per diem and training).
     *
     * @return array<int, string>
     */
    public static function noIncreaseCodesFor(int $year): array
    {
        $all = SystemSetting::get('no_increase_codes');

        return is_array($all) ? self::codes((array) ($all[(string) $year] ?? [])) : [];
    }

    public static function setNoIncreaseCodes(int $year, array|string $codes): void
    {
        $all = SystemSetting::get('no_increase_codes');
        $all = is_array($all) ? $all : [];
        $codes = self::codes($codes);
        if ($codes === []) {
            unset($all[(string) $year]);
        } else {
            $all[(string) $year] = $codes;
        }
        SystemSetting::set('no_increase_codes', $all, 'json', 'Account codes a revision may not increase, per fiscal year (budget execution decree)');
    }

    /** What the institution does with its budget envelope: nothing, record it and compare the plan with it, or also share it. */
    public function envelopeUse(): string
    {
        return $this->budgetDistribution ? 'share' : ($this->budgetDecisions ? 'record' : 'off');
    }

    /** The same options with another envelope use: off, record or share (Settings > Modules switches it). */
    public function withEnvelope(string $use): self
    {
        return new self(
            obligations: $this->obligations, financeInstitutionWide: $this->financeInstitutionWide, requiredFields: $this->requiredFields,
            externalReference: $this->externalReference, correctiveActions: $this->correctiveActions, baselines: $this->baselines,
            structureAtApproval: $this->structureAtApproval, completeBeforeApproval: $this->completeBeforeApproval,
            budgetDecisions: $use !== 'off', budgetDistribution: $use === 'share', ceilingMode: $this->ceilingMode,
            budgetUnitId: $this->budgetUnitId, distributionApprover: $this->distributionApprover, budgetChangeChecks: $this->budgetChangeChecks,
        );
    }

    public static function guidance(int $year): ?string
    {
        $all = SystemSetting::get('planning_guidance');

        return is_array($all) && filled($all[(string) $year] ?? null) ? (string) $all[(string) $year] : null;
    }

    public static function setGuidance(int $year, ?string $text): void
    {
        $all = SystemSetting::get('planning_guidance');
        $all = is_array($all) ? $all : [];
        $text = trim((string) $text);
        if ($text === '') {
            unset($all[(string) $year]);
        } else {
            $all[(string) $year] = mb_substr($text, 0, 4000);
        }
        SystemSetting::set('planning_guidance', $all, 'json', 'Guidance for preparing the plan, per fiscal year');
    }
}
