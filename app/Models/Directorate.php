<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\PlanningWindow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Directorate extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'parent_id',
        'type',
        'administration',
        'legal_form',
        'legal_basis',
        'description',
        'director_name',
        'director_email',
        'active',
        'established_year',
        'dissolved_year',
    ];

    protected static function booted(): void
    {
        // the planning window keeps the unit tree in memory
        static::saved(fn () => PlanningWindow::forgetTree());
        static::deleted(fn () => PlanningWindow::forgetTree());
    }

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

    public const DIRECT = 'direct';

    public const INDIRECT = 'indirect';

    /** A public body with its own legal personality and financial autonomy, supervised by the ministry. */
    public function isAutonomous(): bool
    {
        return $this->administration === self::INDIRECT;
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
        // The whole tree is read once per request (it is small), not once per unit.
        static $children = null;
        if ($children === null || app()->runningUnitTests()) {
            $children = [];
            foreach (self::query()->get(['id', 'parent_id']) as $d) {
                $children[$d->parent_id][] = $d->id;
            }
        }

        $ids = [];
        $queue = [$this->id];
        while ($queue) {
            foreach ($children[array_shift($queue)] ?? [] as $childId) {
                if ($childId !== $this->id && ! in_array($childId, $ids, true)) {
                    $ids[] = $childId;
                    $queue[] = $childId;
                }
            }
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
        return (float) LineItem::whereHas('activity', fn ($q) => $q
            ->whereIn('directorate_id', $this->getSelfAndDescendantIds())
            ->where('fiscal_year', $fiscalYear))
            ->sum('estimated_amount');
    }

    /**
     * Calculate Total Budget Roll-up (Approved)
     */
    public function getTotalApprovedRollup(int $fiscalYear): float
    {
        return (float) AdjustedBudget::whereHas('lineItem', fn ($l) => $l->withTrashed()->whereHas('activity', fn ($q) => $q
            ->whereIn('directorate_id', $this->getSelfAndDescendantIds())
            ->where('fiscal_year', $fiscalYear)))
            ->sum('approved_amount');
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
