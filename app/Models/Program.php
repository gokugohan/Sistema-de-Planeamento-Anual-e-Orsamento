<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A budget programme of one fiscal year. Programmes can differ from year to year, so the same OGE code
 * is registered once for each year it is used; an activity always uses the programme of its own year.
 */
class Program extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = ['fiscal_year', 'name', 'oge_code', 'manager_name', 'objective', 'kpis'];

    protected static function booted(): void
    {
        // A programme registered without a year belongs to the working year.
        static::creating(function (Program $program) {
            $program->fiscal_year ??= FiscalEnvelope::getEffectiveYear();
        });
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function subprograms()
    {
        return $this->hasMany(Subprogram::class);
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('programs.fiscal_year', $year);
    }
}
