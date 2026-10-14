<?php

namespace App\Support;

use App\Models\SystemSetting;

/**
 * What kind of institution this deployment serves, because it decides who the plan goes to and who
 * gives the final approval.
 *
 *  - ministry:     the plan is approved by the Minister and submitted to the CRO (Comité de Revisão Orçamental).
 *  - subordinate:  a directorate, gabinete or agency inside a ministry. The head of the office
 *                  (Director / Chefe do Gabinete) gives the final approval, and the approved plan is
 *                  sent to the ministry, or to the team there that prepares the budget.
 */
final class Institution
{
    public const MINISTRY = 'ministry';

    public const SUBORDINATE = 'subordinate';

    /** Texts that name steps of a ministry's cycle, and what an office says instead. */
    private const OFFICE_TEXT = [
        'Ceiling, CRO submission and decision, State Budget law' => 'Ceiling, sending the plan to the ministry, allocation received',
    ];

    public static function level(): string
    {
        return SystemSetting::get('institution_level') === self::SUBORDINATE ? self::SUBORDINATE : self::MINISTRY;
    }

    public static function isMinistry(): bool
    {
        return self::level() === self::MINISTRY;
    }

    /** Who receives the approved plan when this is not a ministry (null when not set). */
    public static function recipient(): ?string
    {
        $recipient = trim((string) SystemSetting::get('report_recipient', ''));

        return $recipient !== '' ? $recipient : null;
    }

    /**
     * A translated text that may name the CRO. For a ministry it is shown as written; for a subordinate
     * institution the CRO is the ministry, so the acronym is replaced by the word for "Ministry" in the
     * language shown ("Submitted to CRO" becomes "Submitted to Ministry").
     */
    public static function label(string $key, array $replace = []): string
    {
        if (self::isMinistry()) {
            return __($key, $replace);
        }

        // Some texts describe steps an office does not have at all, so they are replaced, not reworded.
        $text = __(self::OFFICE_TEXT[$key] ?? $key, $replace);

        return preg_replace('/\bCRO\b/', self::ministryWord(), $text);
    }

    /** The line under the title of the approved-plan report: who it is submitted to. */
    public static function coverLine(): string
    {
        if (self::isMinistry()) {
            return __('Submission to the Budget Review Committee (CRO)');
        }

        return __('Submitted to: :recipient', ['recipient' => self::recipient() ?? __("the Ministry's budget preparation team")]);
    }

    private static function ministryWord(): string
    {
        return match (app()->getLocale()) {
            'pt' => 'Ministério',
            'tet' => 'Ministériu',
            default => 'Ministry',
        };
    }
}
