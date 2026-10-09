<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Directorate;
use App\Models\EconomicClassification;
use App\Models\FiscalEnvelope;
use App\Models\Indicator;
use App\Models\LineItem;
use App\Models\Program;
use Illuminate\Support\Facades\DB;

/**
 * Imports PAA activities (with indicators and budget lines) from a CSV file.
 *
 * One CSV row = one activity row, one indicator, one budget line, or any
 * combination. Rows sharing a `ref` belong to the same activity; the activity
 * columns are read from the first row of each ref.
 *
 * parse() never writes to the database; import() writes everything in a single
 * transaction, always as Draft.
 */
class ActivityCsvImporter
{
    public const MAX_ROWS = 2000;

    public const COLUMNS = [
        'ref', 'directorate_code', 'program_code', 'description', 'priority', 'quarter',
        'planned_start', 'planned_end', 'procurement_required', 'manager_name',
        'indicator_name', 'indicator_type', 'indicator_unit', 'indicator_target',
        'class_code', 'amount',
    ];

    private const PRIORITIES = [
        'alta' => 'Alta', 'high' => 'Alta',
        'media' => 'Média', 'média' => 'Média', 'medium' => 'Média',
        'baixa' => 'Baixa', 'low' => 'Baixa',
    ];

    /**
     * @return array{activities: array<int, array>, errors: array<int, array{row: int, message: string}>, rows: int}
     */
    public function parse(string $path, int $fiscalYear): array
    {
        $errors = [];
        $rows = $this->readRows($path, $errors);

        if ($errors) {
            return ['activities' => [], 'errors' => $errors, 'rows' => count($rows)];
        }

        $directorates = Directorate::query()->where('active', true)->get()->keyBy(fn ($d) => strtolower($d->code));
        $programs = Program::all()->keyBy(fn ($p) => strtolower((string) $p->oge_code));
        $classes = EconomicClassification::all()->keyBy(fn ($c) => strtolower((string) $c->code));

        $activities = [];

        foreach ($rows as ['line' => $line, 'data' => $r]) {
            $ref = $r['ref'];
            if ($ref === '') {
                $errors[] = $this->err($line, 'Column "ref" is empty; it groups rows into one activity.');

                continue;
            }

            if (! isset($activities[$ref])) {
                $activities[$ref] = $this->buildActivity($line, $r, $fiscalYear, $directorates, $programs, $errors)
                    + ['indicators' => [], 'line_items' => []];
            }

            if ($this->has($r, ['indicator_name', 'indicator_type', 'indicator_unit', 'indicator_target'])) {
                $indicator = $this->buildIndicator($line, $r, $errors);
                if ($indicator) {
                    $activities[$ref]['indicators'][] = $indicator;
                }
            }

            if ($this->has($r, ['class_code', 'amount'])) {
                $item = $this->buildLineItem($line, $r, $classes, $errors);
                if ($item) {
                    $activities[$ref]['line_items'][] = $item;
                }
            }
        }

        $this->rejectExisting($activities, $fiscalYear, $errors);
        $this->rejectClosedYear($fiscalYear, $errors);

        usort($errors, fn ($a, $b) => $a['row'] <=> $b['row']);

        return ['activities' => array_values($activities), 'errors' => $errors, 'rows' => count($rows)];
    }

    /** @return array{activities: int, indicators: int, line_items: int, total: float} */
    public function import(array $activities, int $fiscalYear): array
    {
        $counts = ['activities' => 0, 'indicators' => 0, 'line_items' => 0, 'total' => 0.0];

        DB::transaction(function () use ($activities, $fiscalYear, &$counts) {
            foreach ($activities as $a) {
                $activity = Activity::create([
                    'fiscal_year' => $fiscalYear,
                    'program_id' => $a['program_id'],
                    'directorate_id' => $a['directorate_id'],
                    'description' => $a['description'],
                    'manager_name' => $a['manager_name'],
                    'quarter' => $a['quarter'],
                    'priority' => $a['priority'],
                    'planned_start' => $a['planned_start'],
                    'planned_end' => $a['planned_end'],
                    'procurement_required' => $a['procurement_required'],
                    'status' => Activity::STATUS_DRAFT,
                ]);
                $counts['activities']++;

                foreach ($a['indicators'] as $i) {
                    Indicator::create($i + ['activity_id' => $activity->id]);
                    $counts['indicators']++;
                }

                foreach ($a['line_items'] as $n => $l) {
                    LineItem::create($l + ['activity_id' => $activity->id, 'code' => 'L'.($n + 1)]);
                    $counts['line_items']++;
                    $counts['total'] += $l['estimated_amount'];
                }
            }
        });

        return $counts;
    }

    /** CSV template with the header and one clearly fictional example activity. */
    public static function template(): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::COLUMNS, ';');
        fputcsv($out, ['A1', 'DNTT', '002', 'EXEMPLO - substituir pela atividade real', 'Alta', 2, '2027-04-01', '2027-06-30', 'não', 'Nome do responsável',
            'Número de diplomas elaborados', 'Output', 'diplomas', 3, '20', '15000.00'], ';');
        fputcsv($out, ['A1', '', '', '', '', '', '', '', '', '', '', '', '', '', '10', '5000.00'], ';');
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return "\xEF\xBB\xBF".$csv; // BOM so Excel opens it as UTF-8
    }

    // ── parsing helpers ──────────────────────────────────────────────────

    /** @return array<int, array{line: int, data: array<string,string>}> */
    private function readRows(string $path, array &$errors): array
    {
        $content = @file_get_contents($path);
        if ($content === false || trim($content) === '') {
            $errors[] = $this->err(0, 'The file is empty or unreadable.');

            return [];
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\r\n");
        $delimiter = collect([';' => substr_count($firstLine, ';'), ',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t")])
            ->sortDesc()->keys()->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, 0, $delimiter);
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header ?: []);

        $missing = array_diff(['ref', 'directorate_code', 'program_code', 'description', 'quarter', 'planned_start', 'planned_end'], $header);
        if ($missing) {
            $errors[] = $this->err(1, 'Missing required column(s): '.implode(', ', $missing).'. Download the template for the expected layout.');

            return [];
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($stream, 0, $delimiter)) !== false) {
            $line++;
            if ($cells === [null] || count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = $this->err($line, 'Too many rows; the limit is '.self::MAX_ROWS.' per file. Split the file.');
                break;
            }
            $data = [];
            foreach (self::COLUMNS as $col) {
                $idx = array_search($col, $header, true);
                $data[$col] = $idx === false ? '' : trim((string) ($cells[$idx] ?? ''));
            }
            $rows[] = ['line' => $line, 'data' => $data];
        }
        fclose($stream);

        return $rows;
    }

    private function buildActivity(int $line, array $r, int $year, $directorates, $programs, array &$errors): array
    {
        $dir = $directorates->get(strtolower($r['directorate_code']));
        $prog = $programs->get(strtolower($r['program_code']));

        if (! $dir) {
            $errors[] = $this->err($line, "Unknown or inactive directorate_code \"{$r['directorate_code']}\".");
        }
        if (! $prog) {
            $errors[] = $this->err($line, "Unknown program_code \"{$r['program_code']}\".");
        }
        if (mb_strlen($r['description']) < 5) {
            $errors[] = $this->err($line, 'description must have at least 5 characters.');
        }

        $priority = self::PRIORITIES[mb_strtolower($r['priority'] ?: 'media')] ?? null;
        if (! $priority) {
            $errors[] = $this->err($line, "priority \"{$r['priority']}\" is invalid; use Alta, Média or Baixa.");
        }

        $quarter = ctype_digit($r['quarter']) ? (int) $r['quarter'] : 0;
        if ($quarter < 1 || $quarter > 4) {
            $errors[] = $this->err($line, 'quarter must be 1, 2, 3 or 4.');
        }

        $start = $this->date($r['planned_start']);
        $end = $this->date($r['planned_end']);
        if (! $start || ! $end) {
            $errors[] = $this->err($line, 'planned_start and planned_end must be valid dates (YYYY-MM-DD).');
        } elseif ($end < $start) {
            $errors[] = $this->err($line, 'planned_end is before planned_start.');
        } elseif ((int) substr($start, 0, 4) !== $year || (int) substr($end, 0, 4) !== $year) {
            $errors[] = $this->err($line, "Dates must fall within the selected fiscal year ({$year}).");
        }

        return [
            'ref' => $r['ref'],
            'line' => $line,
            'directorate_id' => $dir?->id,
            'directorate_code' => $r['directorate_code'],
            'program_id' => $prog?->id,
            'description' => $r['description'],
            'manager_name' => $r['manager_name'] !== '' ? $r['manager_name'] : null,
            'quarter' => $quarter,
            'priority' => $priority,
            'planned_start' => $start,
            'planned_end' => $end,
            'procurement_required' => in_array(mb_strtolower($r['procurement_required']), ['1', 'sim', 's', 'yes', 'y', 'true'], true),
        ];
    }

    private function buildIndicator(int $line, array $r, array &$errors): ?array
    {
        $type = ucfirst(strtolower($r['indicator_type']));
        $target = $this->number($r['indicator_target']);
        $problems = [];

        if ($r['indicator_name'] === '') {
            $problems[] = 'indicator_name is required';
        }
        if (! in_array($type, ['Output', 'Outcome'], true)) {
            $problems[] = 'indicator_type must be Output or Outcome';
        }
        if ($r['indicator_unit'] === '') {
            $problems[] = 'indicator_unit is required';
        }
        if ($target === null || $target < 0) {
            $problems[] = 'indicator_target must be a number >= 0';
        }

        if ($problems) {
            $errors[] = $this->err($line, 'Indicator: '.implode('; ', $problems).'.');

            return null;
        }

        return ['name' => $r['indicator_name'], 'type' => $type, 'unit' => $r['indicator_unit'], 'annual_target' => $target];
    }

    private function buildLineItem(int $line, array $r, $classes, array &$errors): ?array
    {
        $class = $classes->get(strtolower($r['class_code']));
        $amount = $this->number($r['amount']);
        $problems = [];

        if (! $class) {
            $problems[] = "unknown class_code \"{$r['class_code']}\"";
        }
        if ($amount === null || $amount < 0) {
            $problems[] = 'amount must be a number >= 0';
        }

        if ($problems) {
            $errors[] = $this->err($line, 'Budget line: '.implode('; ', $problems).'.');

            return null;
        }

        return ['economic_classification_id' => $class->id, 'estimated_amount' => $amount];
    }

    private function rejectExisting(array $activities, int $year, array &$errors): void
    {
        foreach ($activities as $a) {
            if (! $a['directorate_id']) {
                continue;
            }
            $exists = Activity::query()
                ->where('fiscal_year', $year)
                ->where('directorate_id', $a['directorate_id'])
                ->where('description', $a['description'])
                ->exists();
            if ($exists) {
                $errors[] = $this->err($a['line'], 'An activity with this directorate and description already exists for '.$year.'; skipped to avoid duplicates.');
            }
        }
    }

    private function rejectClosedYear(int $year, array &$errors): void
    {
        $status = FiscalEnvelope::where('fiscal_year', $year)->value('status');
        if ($status === FiscalEnvelope::STATUS_CLOSED) {
            $errors[] = $this->err(0, "The {$year} planning cycle is closed; activities cannot be imported.");
        }
    }

    private function has(array $r, array $columns): bool
    {
        foreach ($columns as $c) {
            if ($r[$c] !== '') {
                return true;
            }
        }

        return false;
    }

    private function date(string $value): ?string
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value);

        return $d && $d->format('Y-m-d') === $value ? $value : null;
    }

    private function number(string $value): ?float
    {
        $v = str_replace([' ', "\u{00A0}", '$'], '', $value);
        if (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(',', '', $v);
        } elseif (substr_count($v, ',') === 1) {
            $v = str_replace(',', '.', $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }

    private function err(int $row, string $message): array
    {
        return ['row' => $row, 'message' => $message];
    }
}
