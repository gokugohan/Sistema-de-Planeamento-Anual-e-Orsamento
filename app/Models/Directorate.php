<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Directorate extends Model
{
    use \App\Models\Concerns\Auditable;
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'parent_id',
        'type',
        'description',
        'director_name',
        'director_email',
        'active',
        'established_year',
        'dissolved_year'
    ];

    /**
     * Scope a query to only include directorates that were operational in a given year.
     */
    public function scopeActiveInYear($query, int $year)
    {
        return $query->where(function ($q) use ($year) {
            $q->whereNull('established_year')
                ->orWhere('established_year', '<=', $year);
        })->where(function ($q) use ($year) {
            $q->whereNull('dissolved_year')
                ->orWhere('dissolved_year', '>=', $year);
        })->where('active', true);
    }

    public function isOperationalInYear(int $year): bool
    {
        $established = $this->established_year ?? 0;
        $dissolved = $this->dissolved_year ?? 9999;

        return $this->active && ($year >= $established) && ($year <= $dissolved);
    }

    public function parent()
    {
        return $this->belongsTo(Directorate::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Directorate::class, 'parent_id');
    }

    /**
     * Get all descendant directorate IDs (recursive)
     */
    public function getAllDescendantIds(): array
    {
        $ids = [];
        foreach ($this->children as $child) {
            $ids[] = $child->id;
            $ids = array_merge($ids, $child->getAllDescendantIds());
        }
        return $ids;
    }

    /**
     * Get all descendant directorate IDs including the current one
     */
    public function getSelfAndDescendantIds(): array
    {
        return array_merge([$this->id], $this->getAllDescendantIds());
    }

    /**
     * Calculate Total Budget Roll-up (Estimated)
     */
    public function getTotalEstimatedRollup(int $fiscalYear): float
    {
        $ids = $this->getSelfAndDescendantIds();
        return (float) Activity::whereIn('directorate_id', $ids)
            ->where('fiscal_year', $fiscalYear)
            ->with(['lineItems'])
            ->get()
            ->sum(fn($activity) => $activity->lineItems->sum('estimated_amount'));
    }

    /**
     * Calculate Total Budget Roll-up (Approved)
     */
    public function getTotalApprovedRollup(int $fiscalYear): float
    {
        $ids = $this->getSelfAndDescendantIds();
        return (float) Activity::whereIn('directorate_id', $ids)
            ->where('fiscal_year', $fiscalYear)
            ->with(['lineItems.adjustedBudget'])
            ->get()
            ->sum(fn($activity) => $activity->lineItems->sum(fn($item) => $item->adjustedBudget->approved_amount ?? 0));
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
