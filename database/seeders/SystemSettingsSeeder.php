<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * Neutral defaults. Existing values are never overwritten, so settings changed
 * in the app (Management → Settings) survive a re-seed.
 */
class SystemSettingsSeeder extends Seeder
{
    public function run(): void
    {
        // Workflow & reporting constraints
        $this->defaultSetting('enable_next_year_planning', false, 'boolean', 'Allow users to create PAA activities for the next fiscal year');
        $this->defaultSetting('show_draft_planning_in_reports', false, 'boolean', 'Control visibility of draft data in reports');
        $this->defaultSetting('enable_paa_testing_mode', false, 'boolean', 'Enable testing mode for PAA planning (adds Testing Mode menu)');

        // Who the plan goes to and who approves it: a ministry, or only a directorate / gabinete inside one
        $this->defaultSetting('institution_level', 'ministry', 'string', 'ministry or subordinate (directorate / gabinete inside a ministry)');
        $this->defaultSetting('report_recipient', '', 'string', 'Who receives the approved plan when the institution is not a ministry');

        // Organization identity: replace in Management → Settings
        $this->defaultSetting('organization_name_full', 'Institution Name', 'string', 'Full name of the Ministry/Entity');
        $this->defaultSetting('organization_name_short', 'ORG', 'string', 'Short abbreviation of the organization');
        $this->defaultSetting('organization_logo', null, 'string', 'Path to organization logo file');
        $this->defaultSetting('organization_favicon', null, 'string', 'Path to favicon file');
        $this->defaultSetting('system_owner', 'Institution Name', 'string', 'System owner organization');
        $this->defaultSetting('implemented_by', '', 'string', 'System implementation team');
        $this->defaultSetting('org_name', ['en' => 'Institution Name', 'pt' => 'Nome da Instituição', 'tet' => 'Naran Instituisaun'], 'json', 'Localized organization name');
        $this->defaultSetting('org_abbr', ['en' => 'ORG', 'pt' => 'ORG', 'tet' => 'ORG'], 'json', 'Localized organization abbreviation');
    }

    private function defaultSetting(string $key, $value, string $type, string $description): void
    {
        if (SystemSetting::where('key', $key)->exists()) {
            return;
        }

        SystemSetting::set($key, $value, $type, $description);
    }
}
