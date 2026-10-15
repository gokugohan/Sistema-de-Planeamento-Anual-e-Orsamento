<?php

namespace App\Services;

use App\Models\Program;
use Illuminate\Support\Collection;

/**
 * Programmes are registered per fiscal year. This is what carries them from one year to another:
 * suggestions while registering a new one, copying a year's programmes forward, and finding (or
 * creating) the programme of another year that matches one an activity uses.
 */
class ProgramYears
{
    /**
     * Programmes of OTHER years that match what is being typed (code or name) and are not yet registered
     * in $year, newest version of each code first. Used to auto-complete a new registration.
     *
     * @return Collection<int, Program>
     */
    public function suggestions(int $year, string $term, int $limit = 6): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 1) {
            return collect();
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $term).'%';

        return Program::query()
            ->where('fiscal_year', '!=', $year)
            ->whereNotIn('oge_code', Program::forYear($year)->select('oge_code'))
            ->where(fn ($q) => $q->where('oge_code', 'like', $like)->orWhere('name', 'like', $like))
            ->orderByDesc('fiscal_year')->orderBy('oge_code')
            ->get()
            ->unique('oge_code')
            ->take($limit)
            ->values();
    }

    /**
     * Register in $toYear the programmes of $fromYear that it does not have yet (all of them, or only the given codes).
     *
     * @param  array<int, string>|null  $codes
     * @return array{copied: int, skipped: int}
     */
    public function copy(int $fromYear, int $toYear, ?array $codes = null): array
    {
        $copied = $skipped = 0;
        $have = Program::forYear($toYear)->pluck('oge_code')->map('strval')->all();

        foreach (Program::forYear($fromYear)->when($codes !== null, fn ($q) => $q->whereIn('oge_code', $codes))->orderBy('oge_code')->get() as $p) {
            if (in_array((string) $p->oge_code, $have, true)) {
                $skipped++;

                continue;
            }
            Program::create(['fiscal_year' => $toYear] + $p->only(['oge_code', 'name', 'manager_name', 'objective', 'kpis']));
            $copied++;
        }

        return ['copied' => $copied, 'skipped' => $skipped];
    }

    /** The programme of $year with the same OGE code as $program, registered now if the year does not have it. */
    public function forYearOf(Program $program, int $year): Program
    {
        if ((int) $program->fiscal_year === $year) {
            return $program;
        }

        return Program::forYear($year)->where('oge_code', $program->oge_code)->first()
            ?? Program::create(['fiscal_year' => $year] + $program->only(['oge_code', 'name', 'manager_name', 'objective', 'kpis']));
    }

    /** Years that have programmes registered, newest first. */
    public function years(): Collection
    {
        return Program::query()->distinct()->orderByDesc('fiscal_year')->pluck('fiscal_year')->map(fn ($y) => (int) $y);
    }
}
