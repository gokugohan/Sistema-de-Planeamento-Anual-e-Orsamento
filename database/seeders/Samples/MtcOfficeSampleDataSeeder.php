<?php

namespace Database\Seeders\Samples;

use App\Models\Directorate;
use App\Models\SystemSetting;
use App\Support\Institution;
use Database\Seeders\EconomicClassificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Seeder;

/**
 * The same sample data as the whole-ministry dataset, but for ONE office of the Ministério dos
 * Transportes e Comunicações: a gabinete, a direção-geral or one of the autonomous bodies, with
 * everything under it. It shows the application running for a single office.
 *
 * Only that office's units, programmes, demo accounts and the activities the State Budget plans for
 * it are created; its envelope is the sum of its own plans. The default office is the Gabinete de
 * Digitalização e Segurança Cibernética (GDSC) with its two units, UDIG and USC. The budget books
 * give it only one activity per unit, so for GDSC an ILLUSTRATIVE plan (data/gdsc_illustrative.json,
 * not taken from any published document) is added, with 2027 activities at every step of the workflow.
 *
 *     php artisan db:seed --class="Database\Seeders\Samples\MtcOfficeSampleDataSeeder"
 *
 * Registered as the "mtc" dataset like the full one, so it is purged the same way. It is an
 * alternative to the whole-ministry dataset: load one or the other. Refused in production.
 */
class MtcOfficeSampleDataSeeder extends Seeder
{
    public const DEFAULT_OFFICE = 'GDSC';

    /** Setting that remembers the branding this dataset applied, so a purge can undo exactly that. */
    public const BRANDING_SETTING = 'sample_office_branding';

    /** Where an office of the sample ministry sends its approved plan (a working value; change it in Settings). */
    public const SAMPLE_RECIPIENT = 'Ministério dos Transportes e Comunicações — Direção Nacional do Orçamento e Gestão Financeira (DNOGF)';

    public function run(?string $office = null): void
    {
        $office ??= self::DEFAULT_OFFICE;

        if (app()->isProduction()) {
            throw new \RuntimeException('The sample dataset creates demo accounts with a known password and must not be loaded in production.');
        }

        $units = MtcDirectorateSeeder::subtree($office);
        if (! $units) {
            throw new \InvalidArgumentException("Unknown office '{$office}'.");
        }
        $illustrative = $office === self::DEFAULT_OFFICE;

        $this->call([RolesAndPermissionsSeeder::class, EconomicClassificationSeeder::class]);
        $this->call(MtcDirectorateSeeder::class, true, ['office' => $office]);
        $this->call(MtcProgramSeeder::class, true, ['only' => MtcBudgetSeeder::programmeCodes($units, $illustrative)]);
        $this->branding($office);
        $this->call(MtcDemoUsersSeeder::class, true, ['office' => $office]);
        $this->call(MtcBudgetSeeder::class, true, ['units' => $units, 'illustrative' => $illustrative]);
    }

    /** Shows the office, not the ministry, as the institution. */
    private function branding(string $office): void
    {
        $name = Directorate::where('code', $office)->value('name') ?? $office;

        SystemSetting::set('organization_name_full', $name, 'string', 'Full name of the Ministry/Entity');
        SystemSetting::set('organization_name_short', $office, 'string', 'Short abbreviation of the organization');
        SystemSetting::set('system_owner', $name, 'string', 'System owner organization');
        SystemSetting::set('org_name', ['en' => $name, 'pt' => $name, 'tet' => $name], 'json', 'Localized organization name');
        SystemSetting::set('org_abbr', ['en' => $office, 'pt' => $office, 'tet' => $office], 'json', 'Localized organization abbreviation');
        // An office is not the ministry: its head approves, and its plan goes to the ministry's budget team.
        SystemSetting::set('institution_level', Institution::SUBORDINATE, 'string');
        SystemSetting::set('report_recipient', self::SAMPLE_RECIPIENT, 'string');
        SystemSetting::set(self::BRANDING_SETTING, ['name' => $name, 'abbr' => $office], 'json', 'Branding applied by the office sample dataset');
    }

    /** Undo the office branding, but only the parts the administrator has not changed since. */
    public static function resetBrandingIfUnchanged(): void
    {
        $applied = SystemSetting::get(self::BRANDING_SETTING);
        if (! is_array($applied)) {
            return;
        }

        $name = $applied['name'];
        $abbr = $applied['abbr'];
        if (SystemSetting::get('org_name') === ['en' => $name, 'pt' => $name, 'tet' => $name]) {
            SystemSetting::set('org_name', ['en' => 'Institution Name', 'pt' => 'Nome da Instituição', 'tet' => 'Naran Instituisaun'], 'json');
        }
        if (SystemSetting::get('org_abbr') === ['en' => $abbr, 'pt' => $abbr, 'tet' => $abbr]) {
            SystemSetting::set('org_abbr', ['en' => 'ORG', 'pt' => 'ORG', 'tet' => 'ORG'], 'json');
        }
        if (SystemSetting::get('organization_name_full') === $name) {
            SystemSetting::set('organization_name_full', 'Institution Name', 'string');
            SystemSetting::set('organization_name_short', 'ORG', 'string');
        }
        if (SystemSetting::get('system_owner') === $name) {
            SystemSetting::set('system_owner', 'Institution Name', 'string');
        }
        if (SystemSetting::get('institution_level') === Institution::SUBORDINATE) {
            SystemSetting::set('institution_level', Institution::MINISTRY, 'string');
        }
        if (SystemSetting::get('report_recipient') === self::SAMPLE_RECIPIENT) {
            SystemSetting::set('report_recipient', '', 'string');
        }
        SystemSetting::where('key', self::BRANDING_SETTING)->delete();
        SystemSetting::flushCache();
    }
}
