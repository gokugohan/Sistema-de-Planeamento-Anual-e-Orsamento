<?php

namespace App\Services;

use App\Models\Program;
use App\Models\Subprogram;
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
     * @return array{copied: int, skipped: int, subprograms: int} subprograms = how many subprogrammes were added (also under programmes the year already had)
     */
    public function copy(int $fromYear, int $toYear, ?array $codes = null): array
    {
        $copied = $skipped = $subs = 0;
        $have = Program::forYear($toYear)->get()->keyBy(fn ($p) => (string) $p->oge_code);

        foreach (Program::forYear($fromYear)->when($codes !== null, fn ($q) => $q->whereIn('oge_code', $codes))->with('subprograms')->orderBy('oge_code')->get() as $p) {
            $target = $have->get((string) $p->oge_code);
            if ($target) {
                $skipped++;
            } else {
                $target = Program::create(['fiscal_year' => $toYear] + $p->only(['oge_code', 'name', 'manager_name', 'objective', 'kpis']));
                $copied++;
            }
            $this->copyDetails($p, $target);
            $subs += $this->copySubprograms($p, $target);
        }

        return ['copied' => $copied, 'skipped' => $skipped, 'subprograms' => $subs];
    }

    /** Add to $target the subprogrammes of $source it does not have (by code). Returns how many were added. */
    private function copySubprograms(Program $source, Program $target): int
    {
        $have = $target->subprograms()->pluck('code')->map('strval')->all();
        $added = 0;
        foreach ($source->subprograms as $s) {
            if (! in_array((string) $s->code, $have, true)) {
                $created = $target->subprograms()->create($s->only(['code', 'name', 'objective']));
                $this->copyDetails($s, $created);
                $added++;
            }
        }

        return $added;
    }

    /** Bring over the objectives and result indicators of a programme or subprogramme, unless the target already has its own. */
    private function copyDetails(Program|Subprogram $source, Program|Subprogram $target): void
    {
        $source->loadMissing(['alignments', 'resultIndicators']);
        if ($target->alignments()->doesntExist()) {
            foreach ($source->alignments as $a) {
                $target->alignments()->create($a->only(['framework', 'reference', 'description']));
            }
        }
        if ($target->resultIndicators()->doesntExist()) {
            foreach ($source->resultIndicators as $r) {
                $target->resultIndicators()->create($r->only(['name', 'baseline', 'target', 'means_of_verification']));
            }
        }
    }

    /** The subprogramme of $target (a programme of another year) that matches $sub by code, registered now if it is missing. */
    public function subprogramFor(Subprogram $sub, Program $target): Subprogram
    {
        return $target->subprograms()->where('code', $sub->code)->first()
            ?? tap($target->subprograms()->create($sub->only(['code', 'name', 'objective'])), fn ($created) => $this->copyDetails($sub, $created));
    }

    /** The programme of $year with the same OGE code as $program, registered now if the year does not have it. */
    public function forYearOf(Program $program, int $year): Program
    {
        if ((int) $program->fiscal_year === $year) {
            return $program;
        }

        $target = Program::forYear($year)->where('oge_code', $program->oge_code)->first()
            ?? Program::create(['fiscal_year' => $year] + $program->only(['oge_code', 'name', 'manager_name', 'objective', 'kpis']));
        $this->copyDetails($program, $target);
        $this->copySubprograms($program->loadMissing('subprograms'), $target);

        return $target;
    }

    /** Years that have programmes registered, newest first. */
    public function years(): Collection
    {
        return Program::query()->distinct()->orderByDesc('fiscal_year')->pluck('fiscal_year')->map(fn ($y) => (int) $y);
    }
}
