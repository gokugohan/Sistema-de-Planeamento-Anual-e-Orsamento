<?php

namespace Database\Seeders\Samples;

use App\Models\Directorate;
use App\Models\Program;
use App\Models\SystemSetting;
use App\Services\SampleRegistry;
use App\Support\Institution;
use Database\Seeders\EconomicClassificationSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sample data for any entity of the State Budget 2027 (OgeCatalog): a ministry, a secretariat of state, a municipal
 * authority, an institute or a fund, as its own institution. It loads the entity's divisions as units, its
 * programmes, its name, demo accounts and its annual plan (OgeBudgetSeeder), all registered with the sample, so it is
 * purged like the ministry sample (and its units can be kept as the structure).
 *
 *     php artisan db:seed --class="Database\Seeders\Samples\OgeEntitySampleSeeder"   (loads 017, Ministério da Saúde)
 *
 * An alternative to the MTC sample: load one or the other. Refused in production.
 */
class OgeEntitySampleSeeder extends Seeder
{
    public const DEFAULT_ENTITY = '017';

    /** Setting that remembers the name this dataset gave the institution, so a purge can undo exactly that. */
    public const BRANDING_SETTING = 'sample_entity_branding';

    /** @param  array<int, int|string>|null  $years  the years to load (null: 2027, the plan's own year) */
    public function run(?string $entity = null, string $state = 'approved', ?array $years = null): void
    {
        $entity ??= self::DEFAULT_ENTITY;
        if (app()->isProduction()) {
            throw new \RuntimeException('The sample dataset creates demo accounts with a known password and must not be loaded in production.');
        }
        $data = OgeCatalog::entity($entity);

        DB::transaction(function () use ($entity, $data, $state, $years) {
            $registry = app(SampleRegistry::class);
            $this->call([RolesAndPermissionsSeeder::class, EconomicClassificationSeeder::class]);
            $this->units($registry, $data);
            $this->programmes($registry, $data, $years);
            $this->branding($data);
            $this->call(MtcDemoUsersSeeder::class, true, [
                'head' => $data['kind'] === 'ministry' ? ['email' => 'ministro@gdsc.tl', 'name' => 'Ministro — '.$data['name'], 'role' => 'Minister'] : [],
            ]);
            $this->call(OgeBudgetSeeder::class, true, ['entity' => $entity, 'state' => $state, 'years' => $years]);
        });
    }

    /** The entity's divisions as printed in the book, each under its general directorate when the code group has one. */
    private function units(SampleRegistry $registry, array $data): void
    {
        $made = [];
        foreach ($data['units'] as $u) {
            $made[$u['code']] = $registry->upsert('mtc', Directorate::class, ['code' => $u['code']], [
                'name' => Str::limit($u['name'], 250, ''),
                'type' => $u['type'],
                'parent_id' => null,
                'established_year' => null,
                'active' => true,
                'administration' => Directorate::DIRECT,
                'legal_form' => null,
                'legal_basis' => null,
                'description' => "Código orçamental {$u['div']} ({$data['abbr']}, OGE ".OgeCatalog::YEAR.')',
            ]);
        }
        // then each under its general directorate (a real unit with the same code is never moved)
        foreach ($data['units'] as $u) {
            if ($u['parent'] && isset($made[$u['parent']]) && $registry->isRegistered($made[$u['code']])) {
                $made[$u['code']]->update(['parent_id' => $made[$u['parent']]->id]);
            }
        }
    }

    /** The programmes the entity's plan uses, registered for every year loaded, with the outcome the book prints. */
    private function programmes(SampleRegistry $registry, array $data, ?array $years): void
    {
        $outcomes = collect($data['results'])->where('kind', 'outcome')
            ->groupBy(fn ($r) => strtok($r['code'], '.'))->map(fn ($g) => $g->first()['statement']);
        $used = collect($data['activities'])->pluck('programme')->map(fn ($p) => (string) $p)->unique();

        foreach (MtcBudgetSeeder::years($years ?? [OgeCatalog::YEAR]) as $year) {
            foreach ($used as $code) {
                $registry->upsert('mtc', Program::class, ['fiscal_year' => $year, 'oge_code' => $code], [
                    'name' => Str::limit($data['programmes'][$code] ?? $code, 250, ''),
                    'objective' => isset($outcomes[$code]) && $outcomes[$code] !== '' ? Str::limit($outcomes[$code], 1990, '') : null,
                ]);
            }
        }
    }

    /** Shows the entity as the institution; it plans and approves as a whole institution of its own. */
    private function branding(array $data): void
    {
        $name = $data['name'];
        $abbr = $data['abbr'];
        SystemSetting::set('organization_name_full', $name, 'string', 'Full name of the Ministry/Entity');
        SystemSetting::set('organization_name_short', $abbr, 'string', 'Short abbreviation of the organization');
        SystemSetting::set('system_owner', $name, 'string', 'System owner organization');
        SystemSetting::set('org_name', ['en' => $name, 'pt' => $name, 'tet' => $name], 'json', 'Localized organization name');
        SystemSetting::set('org_abbr', ['en' => $abbr, 'pt' => $abbr, 'tet' => $abbr], 'json', 'Localized organization abbreviation');
        SystemSetting::set('institution_level', Institution::MINISTRY, 'string');
        SystemSetting::set('report_recipient', '', 'string');
        SystemSetting::set(self::BRANDING_SETTING, ['name' => $name, 'abbr' => $abbr], 'json', 'Branding applied by the State Budget entity sample');
    }

    /** Undo the entity's name, but only the parts the administrator has not changed since. */
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
        SystemSetting::where('key', self::BRANDING_SETTING)->delete();
        SystemSetting::flushCache();
    }
}
