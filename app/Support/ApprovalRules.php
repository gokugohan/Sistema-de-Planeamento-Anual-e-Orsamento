<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * The rules of the approval chain that an institution may choose. The statuses themselves are fixed
 * (Draft, Submitted, Reviewed, Validated, Waiting Approval, Approved) because reports, the dashboard
 * and the queries all depend on them; what varies between institutions is who gives the final approval
 * and whether a parent director validates sub-units first.
 *
 * The defaults follow the institution level (see Institution): a ministry is approved by the Minister,
 * a directorate or gabinete by its own head. An administrator can change the rules in Settings (a third, off by default,
 * asks a finance officer to confirm an activity's budget before it is certified); only
 * a difference from the level's preset is stored, so a level without custom rules always follows its preset.
 */
final class ApprovalRules
{
    /** The top-level director sends the validated plan to the Minister, who approves it. */
    public const MINISTER = 'minister';

    /** The head of the top-level unit (Director / Chefe do Gabinete) approves it. */
    public const HEAD = 'head';

    public function __construct(
        public readonly string $finalApprover,
        public readonly bool $parentValidation,
        public readonly bool $budgetConfirmation = false,
    ) {}

    /** The rules a level starts with. */
    public static function preset(string $level): self
    {
        return new self($level === Institution::SUBORDINATE ? self::HEAD : self::MINISTER, true);
    }

    /** The rules in force: the preset of the institution's level, with any stored differences applied. */
    public static function current(): self
    {
        $preset = self::preset(Institution::level());
        $stored = SystemSetting::get('approval_rules');
        if (! is_array($stored)) {
            return $preset;
        }

        return new self(
            in_array($stored['final_approver'] ?? null, [self::MINISTER, self::HEAD], true) ? $stored['final_approver'] : $preset->finalApprover,
            array_key_exists('parent_validation', $stored) ? (bool) $stored['parent_validation'] : $preset->parentValidation,
            (bool) ($stored['budget_confirmation'] ?? false),
        );
    }

    public function headApproves(): bool
    {
        return $this->finalApprover === self::HEAD;
    }

    public function equals(self $other): bool
    {
        return $this->finalApprover === $other->finalApprover && $this->parentValidation === $other->parentValidation && $this->budgetConfirmation === $other->budgetConfirmation;
    }

    /** Store these rules, or forget the stored ones when they are exactly the level's preset. */
    public function save(string $level): void
    {
        if ($this->equals(self::preset($level))) {
            // Model deletes, so that returning to the preset is audited like any other change.
            SystemSetting::where('key', 'approval_rules')->get()->each->delete();
            SystemSetting::flushCache();

            return;
        }

        SystemSetting::set('approval_rules', ['final_approver' => $this->finalApprover, 'parent_validation' => $this->parentValidation, 'budget_confirmation' => $this->budgetConfirmation], 'json', 'Approval chain rules that differ from the preset of the institution level');
    }
}
