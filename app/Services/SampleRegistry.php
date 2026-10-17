<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\Program;
use App\Models\Subprogram;
use App\Models\SampleRecord;
use App\Models\SystemSetting;
use App\Models\User;
use Database\Seeders\Samples\MtcBrandingSeeder;
use Database\Seeders\Samples\MtcOfficeSampleDataSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Tracks which records a sample dataset created and purges exactly those.
 *
 * Safety rules:
 *  - A record that already exists and is NOT registered is real data: seeding
 *    never modifies it (it is reported as skipped).
 *  - Purge deletes only registered records, and never anything that real data
 *    still depends on: a sample unit or programme that now has a real activity,
 *    user or child unit is KEPT (and becomes ordinary data); Admin users are
 *    never deleted.
 */
class SampleRegistry
{
    public const FLAG = 'sample_data_active';

    /** Adopt-mode: register existing records that are identical to the sample (loaded before the registry existed). */
    public bool $adopt = false;

    private bool $flagged = false;

    /** @var array<int, string> records the seeding left alone because they already exist as real data */
    public array $skipped = [];

    /**
     * Create the record, or update it if it is already part of this dataset.
     * A pre-existing unregistered record is left untouched.
     */
    public function upsert(string $dataset, string $modelClass, array $keys, array $values): Model
    {
        /** @var Model $existing */
        $existing = $modelClass::query()->withoutGlobalScopes()->where($keys)->first();

        if ($existing) {
            if ($this->isRegistered($existing)) {
                $existing->update($values);

                return $existing;
            }

            if ($this->adopt && $this->identical($existing, $values)) {
                $this->register($dataset, $existing);

                return $existing;
            }

            $this->skipped[] = class_basename($modelClass).' '.json_encode($keys, JSON_UNESCAPED_UNICODE);

            return $existing;
        }

        $model = $modelClass::create($keys + $values);
        $this->register($dataset, $model);

        return $model;
    }

    public function register(string $dataset, Model $model): void
    {
        SampleRecord::firstOrCreate(
            ['model_type' => $model->getMorphClass(), 'model_id' => $model->getKey()],
            ['dataset' => $dataset]
        );
        if (! $this->flagged) {
            SystemSetting::set(self::FLAG, true, 'boolean', 'A sample dataset is loaded');
            $this->flagged = true;
        }
    }

    public function isRegistered(Model $model): bool
    {
        return SampleRecord::where('model_type', $model->getMorphClass())->where('model_id', $model->getKey())->exists();
    }

    public function hasSamples(): bool
    {
        return SampleRecord::exists();
    }

    /** @return array{activities: int, users: int, directorates: int, programs: int} */
    public function counts(?string $dataset = null): array
    {
        $q = fn (string $class) => SampleRecord::query()
            ->when($dataset, fn ($x) => $x->where('dataset', $dataset))
            ->where('model_type', (new $class)->getMorphClass())
            ->count();

        return [
            'activities' => $q(Activity::class),
            'users' => $q(User::class),
            'directorates' => $q(Directorate::class),
            'programs' => $q(Program::class),
        ];
    }

    /**
     * @return array{deleted: array<string,int>, kept: array<int,string>}
     */
    public function purge(string $dataset): array
    {
        $report = DB::transaction(function () use ($dataset) {
            $deleted = ['activities' => 0, 'users' => 0, 'directorates' => 0, 'programs' => 0];
            $kept = [];

            $ids = fn (string $class) => SampleRecord::where('dataset', $dataset)
                ->where('model_type', (new $class)->getMorphClass())
                ->pluck('model_id');

            // 1. Sample activities (indicators and budget lines go with them).
            Activity::withoutGlobalScopes()->whereIn('id', $ids(Activity::class))->get()->each(function ($a) use (&$deleted) {
                $a->delete();
                $deleted['activities']++;
            });

            // 2. Demo users, never an administrator.
            User::whereIn('id', $ids(User::class))->get()->each(function ($u) use (&$deleted, &$kept) {
                if ($u->hasRole('Admin')) {
                    $kept[] = "User {$u->email} (administrator)";

                    return;
                }
                $u->delete();
                $deleted['users']++;
            });

            // 3. Sample units, leaves first, skipping any that real data still uses.
            $units = Directorate::whereIn('id', $ids(Directorate::class))->get()->keyBy('id');
            do {
                $progress = false;
                foreach ($units as $id => $unit) {
                    $inUse = Activity::withoutGlobalScopes()->where('directorate_id', $id)->exists()
                        || User::where('directorate_id', $id)->exists()
                        || Directorate::where('parent_id', $id)->exists();
                    if (! $inUse) {
                        $unit->delete();
                        $units->forget($id);
                        $deleted['directorates']++;
                        $progress = true;
                    }
                }
            } while ($progress && $units->isNotEmpty());
            foreach ($units as $unit) {
                $kept[] = "Directorate {$unit->code} (in use)";
            }

            // 4a. Sample subprogrammes that no remaining activity uses (the ones under a deleted programme go with it).
            Subprogram::whereIn('id', $ids(Subprogram::class))->get()->each(function ($s) {
                if (! Activity::withoutGlobalScopes()->where('subprogram_id', $s->id)->exists()) {
                    $s->delete();
                }
            });

            // 4. Sample programmes that no remaining activity uses.
            Program::whereIn('id', $ids(Program::class))->get()->each(function ($p) use (&$deleted, &$kept) {
                if (Activity::withoutGlobalScopes()->where('program_id', $p->id)->exists()) {
                    $kept[] = "Programme {$p->oge_code} (in use)";

                    return;
                }
                $p->delete();
                $deleted['programs']++;
            });

            // 5. Whatever is left is real data now: stop tracking it.
            SampleRecord::where('dataset', $dataset)->delete();

            if ($dataset === 'mtc') {
                MtcBrandingSeeder::resetIfUnchanged();
                MtcOfficeSampleDataSeeder::resetBrandingIfUnchanged();
            }
            SystemSetting::set(self::FLAG, false, 'boolean', 'A sample dataset is loaded');

            AuditLog::create([
                'action' => 'sample_data.purged',
                'user_id' => auth()->id(),
                'description' => "Sample dataset '{$dataset}' purged",
                'metadata' => ['deleted' => $deleted, 'kept' => $kept],
            ]);

            return ['deleted' => $deleted, 'kept' => $kept];
        });

        return $report;
    }

    /** Same-content check used when adopting records created before the registry existed. */
    private function identical(Model $existing, array $values): bool
    {
        if (isset($values['name'])) {
            return $existing->getAttribute('name') === $values['name'];
        }

        return isset($values['description']) && $existing->getAttribute('description') === $values['description'];
    }
}
