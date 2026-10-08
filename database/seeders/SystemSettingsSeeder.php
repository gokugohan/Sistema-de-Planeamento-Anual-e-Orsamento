<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SystemSetting;

class SystemSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Workflow & Reporting Constraints
        SystemSetting::set('enable_next_year_planning', false, 'boolean', 'Allow users to create PAA activities for the next fiscal year');
        SystemSetting::set('show_draft_planning_in_reports', false, 'boolean', 'Control visibility of draft data in reports');
        SystemSetting::set('enable_paa_testing_mode', false, 'boolean', 'Enable testing mode for PAA planning (adds Testing Mode menu)');

        // Organization Identity
        SystemSetting::set('organization_name_full', 'Ministério dos Transportes e Comunicações', 'string', 'Full name of the Ministry/Entity');
        SystemSetting::set('organization_name_short', 'MTC', 'string', 'Short abbreviation of the organization');
        SystemSetting::set('organization_logo', null, 'string', 'Path to organization logo file');
        SystemSetting::set('organization_favicon', null, 'string', 'Path to favicon file');
        SystemSetting::set('org_name', ['en' => 'Ministry of Transport and Communications', 'pt' => 'Ministério dos Transportes e Comunicações', 'tet' => 'Ministériu Transporte no Komunikasaun'], 'json', 'Localized organization name');
        SystemSetting::set('org_abbr', ['en' => 'MTC', 'pt' => 'MTC', 'tet' => 'MTK'], 'json', 'Localized organization abbreviation');
        SystemSetting::set('system_owner', 'Ministério dos Transportes e Comunicações', 'string', 'System owner organization');
        SystemSetting::set('implemented_by', 'IT Department', 'string', 'System implementation team');
    }

}
