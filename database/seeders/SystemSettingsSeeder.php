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
        SystemSetting::set('organization_name_full', 'Ministério da Administração Estatal', 'string', 'Full name of the Ministry/Entity');
        SystemSetting::set('organization_name_short', 'MAE', 'string', 'Short abbreviation of the organization');
        SystemSetting::set('organization_logo', null, 'string', 'Path to organization logo file');
        SystemSetting::set('organization_favicon', null, 'string', 'Path to favicon file');
        SystemSetting::set('system_owner', 'Ministério da Administração Estatal', 'string', 'System owner organization');
        SystemSetting::set('implemented_by', 'IT Department', 'string', 'System implementation team');
    }

}
