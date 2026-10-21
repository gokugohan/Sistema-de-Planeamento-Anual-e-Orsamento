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
    ) {}

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
        ];
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
