<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** The plan of one directorate (a top-level unit) for one fiscal year has been finalized: its planning has ended. */
class UnitPlanning extends Model
{
    use Auditable;

    protected $fillable = ['fiscal_year', 'directorate_id', 'planning_by', 'planning_at', 'planning_remark'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Services\PlanningWindow::forgetTree());
        static::deleted(fn () => \App\Services\PlanningWindow::forgetTree());
    }

    protected $casts = ['planning_at' => 'datetime'];

    public function directorate()
    {
        return $this->belongsTo(Directorate::class);
    }
}
