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
        'implementation',
        'plan_months',
    ];

    protected $casts = ['due_quarters' => 'array', 'plan_months' => 'array'];

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

    /** The months in which it is planned to be implemented: the ones given, otherwise every month of the quarters it is due in. @return array<int, int> */
    public function plannedMonths(): array
    {
        $given = array_values(array_unique(array_filter(array_map('intval', (array) $this->plan_months), fn ($n) => $n >= 1 && $n <= 12)));
        sort($given);
        if ($given) {
            return $given;
        }

        return collect($this->dueQuarters())->flatMap(fn ($q) => range($q * 3 - 2, $q * 3))->all();
    }

    public function hasMonthPlan(): bool
    {
        return ! empty($this->plan_months);
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
