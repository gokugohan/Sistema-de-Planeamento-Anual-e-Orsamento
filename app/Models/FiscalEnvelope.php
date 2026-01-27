<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FiscalEnvelope extends Model
{
    use HasFactory;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Models\Scopes\ProductionScope);
    }

    const STATUS_DRAFT = 'Draft';
    const STATUS_PENDING = 'Pending Approval';
    const STATUS_APPROVED = 'Approved';
    const STATUS_CLOSED = 'Closed';

    protected $fillable = [
        'fiscal_year',
        'reference_number',
        'source_authority',
        'total_amount',
        'status',
        'approved_date',
        'start_date',
        'due_date',
        'is_test_data',
    ];

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    protected function casts(): array
    {
        return [
            'approved_date' => 'date',
            'start_date' => 'date',
            'due_date' => 'date',
            'is_test_data' => 'boolean',
        ];
    }


    public function getExecutionRateAttribute()
    {
        // Placeholder: Needs actual implementation based on Expenditure Tracking
        // For now return random or 0
        return 0; // To be implemented with Expenditure module
    }

    /**
     * Check if this envelope is currently active based on dates
     */
    public function isActiveByDate(): bool
    {
        if ($this->status !== self::STATUS_APPROVED) {
            return false;
        }

        if (!$this->start_date || !$this->due_date) {
            return false;
        }

        $today = now();
        return $today->greaterThanOrEqualTo($this->start_date) &&
            $today->lessThanOrEqualTo($this->due_date);
    }

    /**
     * Scope: Get currently active envelope by date
     */
    public function scopeActiveByDate($query)
    {
        $today = now();
        return $query->where('status', self::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('due_date', '>=', $today);
    }

    /**
     * Get the fiscal year of the currently active envelope
     */
    public static function getActiveFiscalYear(): ?int
    {
        $activeEnvelope = self::activeByDate()->first();
        return $activeEnvelope ? $activeEnvelope->fiscal_year : null;
    }

    /**
     * Get the currently active envelope
     */
    public static function getActiveEnvelope(): ?self
    {
        return self::activeByDate()->first();
    }

    /**
     * Get the dynamic effective year for the system.
     * Priority: Active Fiscal Envelope -> Current Calendar Year
     */
    public static function getEffectiveYear(): int
    {
        return self::getActiveFiscalYear() ?? (int) date('Y');
    }

    /**
     * Scope: Exclude test data (production only)
     * Explicitly included for cases where global scope might be disabled
     */
    public function scopeProduction($query)
    {
        return $query->where('is_test_data', false);
    }

    /**
     * Scope: Only test data (Bypasses Global Scope)
     */
    public function scopeTestData($query)
    {
        return $query->withoutGlobalScope(\App\Models\Scopes\ProductionScope::class)->where('is_test_data', true);
    }



    /**
     * Relationship: Fiscal envelope has many category allocations
     */
    public function categoryAllocations()
    {
        return $this->hasMany(FiscalEnvelopeCategoryAllocation::class);
    }

    /**
     * Get total planned amount from budget proposals for this fiscal year
     */
    public function getTotalPlannedAmount(): float
    {
        return LineItem::whereHas('activity', function ($query) {
            $query->where('fiscal_year', $this->fiscal_year);
        })->sum('estimated_amount');
    }


    /**
     * Get variance summary for this fiscal envelope
     */
    public function getVarianceSummary(): array
    {
        $totalAllocated = $this->categoryAllocations()->sum('allocated_amount');
        $totalPlanned = $this->categoryAllocations()->sum('planned_amount');

        return [
            'total_allocated' => $totalAllocated,
            'total_planned' => $totalPlanned,
            'total_variance' => $totalAllocated - $totalPlanned,
            'over_allocated_categories' => $this->categoryAllocations()
                ->where('variance_status', 'over_allocated')->count(),
            'under_allocated_categories' => $this->categoryAllocations()
                ->where('variance_status', 'under_allocated')->count(),
            'balanced_categories' => $this->categoryAllocations()
                ->where('variance_status', 'balanced')->count(),
        ];
    }
}
