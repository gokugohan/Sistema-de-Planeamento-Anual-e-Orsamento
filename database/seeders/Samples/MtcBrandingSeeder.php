<?php

namespace Database\Seeders\Samples;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/** Sets the organisation identity shown in the app to the sample institution (MTC). */
class MtcBrandingSeeder extends Seeder
{
    public const ORG_NAME = ['en' => 'Ministry of Transport and Communications', 'pt' => 'Ministério dos Transportes e Comunicações', 'tet' => 'Ministériu Transporte no Komunikasaun'];

    public const ORG_ABBR = ['en' => 'MTC', 'pt' => 'MTC', 'tet' => 'MTK'];

    public const FULL_NAME = 'Ministério dos Transportes e Comunicações';

    public function run(): void
    {
        SystemSetting::set('organization_name_full', self::FULL_NAME, 'string', 'Full name of the Ministry/Entity');
        SystemSetting::set('organization_name_short', 'MTC', 'string', 'Short abbreviation of the organization');
        SystemSetting::set('system_owner', self::FULL_NAME, 'string', 'System owner organization');
        SystemSetting::set('org_name', self::ORG_NAME, 'json', 'Localized organization name');
        SystemSetting::set('org_abbr', self::ORG_ABBR, 'json', 'Localized organization abbreviation');

        // The whole ministry: the Minister approves and the plan goes to the CRO.
        SystemSetting::set('institution_level', 'ministry', 'string');
        SystemSetting::set('report_recipient', '', 'string');
    }

    /**
     * Undo the sample branding, but only the parts the administrator has not
     * changed since: anything they customised is left alone.
     */
    public static function resetIfUnchanged(): void
    {
        $neutral = [
            'org_name' => ['en' => 'Institution Name', 'pt' => 'Nome da Instituição', 'tet' => 'Naran Instituisaun'],
            'org_abbr' => ['en' => 'ORG', 'pt' => 'ORG', 'tet' => 'ORG'],
        ];

        if (SystemSetting::get('org_name') === self::ORG_NAME) {
            SystemSetting::set('org_name', $neutral['org_name'], 'json');
        }
        if (SystemSetting::get('org_abbr') === self::ORG_ABBR) {
            SystemSetting::set('org_abbr', $neutral['org_abbr'], 'json');
        }
        if (SystemSetting::get('organization_name_full') === self::FULL_NAME) {
            SystemSetting::set('organization_name_full', 'Institution Name', 'string');
            SystemSetting::set('organization_name_short', 'ORG', 'string');
        }
        if (SystemSetting::get('system_owner') === self::FULL_NAME) {
            SystemSetting::set('system_owner', 'Institution Name', 'string');
        }
    }
}
