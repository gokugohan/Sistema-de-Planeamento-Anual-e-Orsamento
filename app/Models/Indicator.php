<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Indicator extends Model
{
    use Auditable;
    use HasFactory;

    /** Each quarter adds to the total (counts: reports published, processes digitized). */
    public const CUMULATIVE = 'cumulative';

    /** The latest quarter's value is where the indicator stands (rates and states: % reached). */
    public const LEVEL = 'level';

    protected $fillable = [
        'activity_id',
        'name',
        'type',
        'unit',
        'measure',
        'annual_target',
        'baseline',
        'target_text',
        'means_of_verification',
        'funding_source',
        'due_quarters',
    ];

    protected $casts = ['due_quarters' => 'array'];

    protected static function booted(): void
    {
        // progress reported against an indicator keeps it: a revision changes its target instead of removing it
        static::deleting(function (Indicator $indicator) {
            if ($indicator->progress()->exists()) {
                throw new \DomainException(__('This indicator already has progress reported against it: change its target instead of removing it.'));
            }
        });
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function progress()
    {
        return $this->hasMany(IndicatorProgress::class);
    }

    /** What a unit of measure suggests: a percentage is a level, anything else is counted up. */
    public static function defaultMeasure(?string $unit): string
    {
        return trim((string) $unit) === '%' ? self::LEVEL : self::CUMULATIVE;
    }

    /** The quarters in which progress is reported: every quarter unless the indicator says otherwise. @return array<int, int> */
    public function dueQuarters(): array
    {
        $q = array_values(array_filter(array_map('intval', (array) $this->due_quarters), fn ($n) => $n >= 1 && $n <= 4));

        return $q ?: [1, 2, 3, 4];
    }

    /** The numbered actions of its implementation plan, each with its months. */
    public function actions()
    {
        return $this->hasMany(IndicatorAction::class)->orderBy('position')->orderBy('id');
    }

    /** The months in which it is planned to be implemented: those of its actions, otherwise every month of the quarters it is due in. @return array<int, int> */
    public function plannedMonths(): array
    {
        $given = $this->actions->flatMap(fn (IndicatorAction $a) => $a->monthList())->unique()->sort()->values()->all();

        return $given ?: collect($this->dueQuarters())->flatMap(fn ($q) => range($q * 3 - 2, $q * 3))->all();
    }

    public function hasMonthPlan(): bool
    {
        return $this->actions->contains(fn (IndicatorAction $a) => $a->monthList() !== []);
    }

    /** What is planned, in one line (its actions, in order). */
    public function implementationText(): string
    {
        return $this->actions->pluck('text')->filter()->implode('; ');
    }

    public function isDueIn(int $quarter): bool
    {
        return in_array($quarter, $this->dueQuarters(), true);
    }

    public function isLevel(): bool
    {
        return $this->measure === self::LEVEL;
    }

    /**
     * Where the indicator stands after the given (verified) quarterly values, keyed by quarter: their sum
     * for a cumulative indicator, the latest one for a level.
     *
     * @param  Collection<int|string, float|int|string>  $byQuarter
     */
    public function standing(Collection $byQuarter): float
    {
        if ($byQuarter->isEmpty()) {
            return 0.0;
        }

        return $this->isLevel() ? (float) $byQuarter->sortKeys()->last() : (float) $byQuarter->sum();
    }
}
