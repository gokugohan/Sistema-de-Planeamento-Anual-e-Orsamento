<?php

namespace App\Console\Commands;

use App\Models\EconomicClassification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills a development database with a realistic volume of activities, so page speed can be measured
 * with the data of a real year instead of a few hundred rows. Everything it creates carries the code
 * prefix "LOAD" and is removed again by --purge. Refused in production.
 *
 *     php artisan paa:load-test --activities=20000 --units=60
 *     php artisan paa:load-test --purge
 */
class SeedLoadTestData extends Command
{
    protected $signature = 'paa:load-test {--activities=20000 : activities to create} {--units=60 : organisational units to spread them over} {--year= : fiscal year (default: the working year)} {--purge : remove everything this command created}';

    protected $description = 'Create (or remove) a large synthetic plan for measuring performance. Development only.';

    private const STATUSES = [
        'Draft' => 25, 'Submitted' => 15, 'Reviewed' => 10, 'Validated' => 10, 'Waiting Approval' => 10, 'Approved' => 30,
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused in production.');

            return self::FAILURE;
        }

        return $this->option('purge') ? $this->purge() : $this->seed();
    }

    private function seed(): int
    {
        $year = (int) ($this->option('year') ?: \App\Support\FiscalYear::current());
        $total = max(1, (int) $this->option('activities'));
        $unitCount = max(1, (int) $this->option('units'));
        $started = microtime(true);

        $classes = EconomicClassification::pluck('id')->all();
        if (! $classes) {
            $this->error('There are no economic classifications; run the base seeders first.');

            return self::FAILURE;
        }

        [$programIds, $unitIds] = DB::transaction(function () use ($unitCount, $year) {
            $now = now();
            $programId = DB::table('programs')->where('oge_code', 'LOAD')->where('fiscal_year', $year)->value('id')
                ?? DB::table('programs')->insertGetId(['fiscal_year' => $year, 'oge_code' => 'LOAD', 'name' => 'Load test programme', 'created_at' => $now, 'updated_at' => $now]);

            $rootId = DB::table('directorates')->where('code', 'LOAD')->value('id')
                ?? DB::table('directorates')->insertGetId([
                    'code' => 'LOAD', 'name' => 'Load test directorate-general', 'type' => 'General Directorate',
                    'administration' => 'direct', 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);

            $units = [];
            for ($i = 1; $i <= $unitCount; $i++) {
                $code = sprintf('LOAD-U%03d', $i);
                $units[] = DB::table('directorates')->where('code', $code)->value('id')
                    ?? DB::table('directorates')->insertGetId([
                        'code' => $code, 'name' => "Load test unit {$i}", 'type' => 'Unit', 'parent_id' => $rootId,
                        'administration' => 'direct', 'active' => true, 'created_at' => $now, 'updated_at' => $now,
                    ]);
            }

            return [[$programId], $units];
        });

        $statusPool = [];
        foreach (self::STATUSES as $status => $weight) {
            $statusPool = array_merge($statusPool, array_fill(0, $weight, $status));
        }

        $made = 0;
        foreach (array_chunk(range(1, $total), 1000) as $chunk) {
            DB::transaction(function () use ($chunk, $year, $unitIds, $programIds, $statusPool, $classes) {
                $now = now();
                $rows = [];
                foreach ($chunk as $n) {
                    $rows[] = [
                        'code' => sprintf('LOAD-%d-%06d', $year, $n),
                        'fiscal_year' => $year,
                        'program_id' => $programIds[0],
                        'directorate_id' => $unitIds[$n % count($unitIds)],
                        'description' => "Load test activity {$n}: synthetic activity used to measure page speed",
                        'location' => 'Dili',
                        'quarter' => 1 + $n % 4,
                        'execution_quarters' => json_encode([1, 2, 3, 4]),
                        'period_type' => 'annual',
                        'priority' => ['Alta', 'Média', 'Baixa'][$n % 3],
                        'planned_start' => "{$year}-01-01",
                        'planned_end' => "{$year}-12-31",
                        'status' => $statusPool[$n % count($statusPool)],
                        'is_test_data' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('activities')->insert($rows);

                $ids = DB::table('activities')->whereIn('code', array_column($rows, 'code'))->where('fiscal_year', $year)->pluck('id', 'code');
                $lines = [];
                $indicators = [];
                foreach ($rows as $i => $r) {
                    $id = $ids[$r['code']];
                    foreach (range(1, 2 + $i % 3) as $k) {
                        $lines[] = [
                            'activity_id' => $id, 'code' => $r['code']."-{$k}",
                            'economic_classification_id' => $classes[($i + $k) % count($classes)],
                            'estimated_amount' => 1000 * (1 + ($i * 7 + $k * 13) % 90),
                            'is_test_data' => false, 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                    $indicators[] = [
                        'activity_id' => $id, 'name' => 'Load test indicator', 'type' => 'Output', 'unit' => 'units',
                        'annual_target' => 10 + $i % 90, 'created_at' => $now, 'updated_at' => $now,
                    ];
                }
                DB::table('line_items')->insert($lines);
                DB::table('indicators')->insert($indicators);
            });
            $made += count($chunk);
            $this->output->write("\r{$made}/{$total} activities");
        }

        $this->newLine();
        $this->info(sprintf('Created %d activities over %d units for %d in %.1fs. Remove with: php artisan paa:load-test --purge', $made, count($unitIds), $year, microtime(true) - $started));

        return self::SUCCESS;
    }

    private function purge(): int
    {
        DB::transaction(function () {
            $a = DB::table('activities')->where('code', 'like', 'LOAD-%')->delete(); // budget lines and indicators cascade
            $d = DB::table('directorates')->where('code', 'like', 'LOAD-U%')->delete();
            DB::table('directorates')->where('code', 'LOAD')->delete();
            DB::table('programs')->where('oge_code', 'LOAD')->delete();
            $this->info("Removed {$a} activities and {$d} units.");
        });

        return self::SUCCESS;
    }
}
