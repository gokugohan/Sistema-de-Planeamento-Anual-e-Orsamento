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
    ];

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
