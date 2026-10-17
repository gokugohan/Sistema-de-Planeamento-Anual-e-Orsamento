<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * The rules of the approval chain that an institution may choose. The statuses themselves are fixed
 * (Draft, Submitted, Reviewed, Validated, Approved) because reports, the dashboard and the queries all depend
 * on them. The chain is the same everywhere: the unit prepares, its head certifies, optionally the head of the
 * unit above validates, and the head of the top-level unit (the directorate) approves the plans of its own
 * directorate. Who exactly acts at each step can be named per unit (see ApproverLists).
 *
 * What an institution may choose: whether the head of the unit above validates sub-units first, and whether
 * a finance officer confirms the budget of an activity before it is certified. Only a difference from the
 * preset is stored, so an institution without custom rules always follows the preset.
 */
final class ApprovalRules
{
    public function __construct(
        public readonly bool $parentValidation = true,
        public readonly bool $budgetConfirmation = false,
        /** Does the person who prepared an activity also certify or approve it? off: allowed; flag: allowed and marked in the trail; block: refused (an administrator's override is marked). */
        public readonly string $segregation = self::SEGREGATION_OFF,
    ) {}

    public const SEGREGATION_OFF = 'off';

    public const SEGREGATION_FLAG = 'flag';

    public const SEGREGATION_BLOCK = 'block';

    public const SEGREGATION_MODES = [self::SEGREGATION_OFF, self::SEGREGATION_FLAG, self::SEGREGATION_BLOCK];

    /** The rules an institution starts with. */
    public static function preset(string $level = ''): self
    {
        return new self(true, false);
    }

    /** The rules in force: the preset, with any stored differences applied. */
    public static function current(): self
    {
        $preset = self::preset();
        $stored = SystemSetting::get('approval_rules');
        if (! is_array($stored)) {
            return $preset;
        }

        return new self(
            array_key_exists('parent_validation', $stored) ? (bool) $stored['parent_validation'] : $preset->parentValidation,
            (bool) ($stored['budget_confirmation'] ?? false),
            in_array($stored['segregation'] ?? null, self::SEGREGATION_MODES, true) ? $stored['segregation'] : self::SEGREGATION_OFF,
        );
    }

    public function equals(self $other): bool
    {
        return $this->parentValidation === $other->parentValidation && $this->budgetConfirmation === $other->budgetConfirmation && $this->segregation === $other->segregation;
    }

    /** Store these rules, or forget the stored ones when they are exactly the preset. */
    public function save(string $level = ''): void
    {
        if ($this->equals(self::preset())) {
            // Model deletes, so that returning to the preset is audited like any other change.
            SystemSetting::where('key', 'approval_rules')->get()->each->delete();
            SystemSetting::flushCache();

            return;
        }

        SystemSetting::set('approval_rules', ['parent_validation' => $this->parentValidation, 'budget_confirmation' => $this->budgetConfirmation, 'segregation' => $this->segregation], 'json', 'Approval chain rules that differ from the preset');
    }
}
