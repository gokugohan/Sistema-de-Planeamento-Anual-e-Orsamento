<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Scopes\ProductionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\Institution;
use Illuminate\Database\Eloquent\Model;

class FiscalEnvelope extends Model
{
    use Auditable;
    use HasFactory;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new ProductionScope);

        $forget = fn () => self::forgetMemos();
        static::saved($forget);
        static::deleted($forget);
    }

    /** Drop the per-request memos (called when envelopes or activities change). */
    public static function forgetMemos(): void
    {
        self::$activeMemo = null;
        self::$defaultYearMemo = null;
        self::$closedMemo = [];
        \App\Services\PlanningWindow::forgetTree();
    }

    /** Whether the envelope for this year is closed (memoised: asked once per activity row). */
    public static function isYearClosed(int $year): bool
    {
        if (app()->runningUnitTests()) {
            return self::closedStatus($year);
        }

        return self::$closedMemo[$year] ??= self::closedStatus($year);
    }

    private static function closedStatus(int $year): bool
    {
        return self::where('fiscal_year', $year)->value('status') === self::STATUS_CLOSED;
    }

    /** Per-request memo: [0 => ?FiscalEnvelope] */
    private static ?array $activeMemo = null;

    private static ?int $defaultYearMemo = null;

    /** @var array<int, bool> */
    private static array $closedMemo = [];

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
        'budget_law_reference',
        'promulgation_date',
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

    /**
     * A ministry's envelope is real once the OGE law is approved and promulgated. For a directorate or
     * gabinete the envelope is the allocation its ministry gives it: there is no law of its own to wait
     * for, so the reference of the ministry's decision is optional.
     */
    public function hasLegalBasis(): bool
    {
        if (! Institution::isMinistry()) {
            return true;
        }

        return filled($this->budget_law_reference) && $this->promulgation_date !== null;
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
            'promulgation_date' => 'date',
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

        if (! $this->start_date || ! $this->due_date) {
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
        return self::getActiveEnvelope()?->fiscal_year;
    }

    /**
     * Get the currently active envelope
     */
    public static function getActiveEnvelope(): ?self
    {
        return (self::$activeMemo ??= [self::activeByDate()->first()])[0];
    }

    /**
     * Get the dynamic effective year for the system.
     * Priority: Active Fiscal Envelope -> Current Calendar Year
     */
    public static function getEffectiveYear(): int
    {
        $chosen = (int) session('fiscal_year', 0);

        return $chosen && in_array($chosen, self::availableYears(), true) ? $chosen : self::defaultYear();
    }

    /**
     * The year to work in when the user has not picked one: the year of the active
     * envelope; between budget cycles, the latest year that has an envelope or
     * activities (never beyond next year); otherwise the calendar year. Without
     * this the app shows the wrong, empty year during the planning window
     * (e.g. October 2026, when everything belongs to 2027).
     */
    public static function defaultYear(): int
    {
        if (self::$defaultYearMemo !== null && ! app()->runningUnitTests()) {
            return self::$defaultYearMemo;
        }

        return self::$defaultYearMemo = self::resolveDefaultYear();
    }

    private static function resolveDefaultYear(): int
    {
        if ($active = self::getActiveFiscalYear()) {
            return $active;
        }

        $current = (int) date('Y');
        $planned = array_filter(self::availableYears(), fn ($y) => $y <= $current + 1 && self::yearHasData($y));

        return $planned ? max($planned) : $current;
    }

    /**
     * Can new activities still be planned for this year? Planning ends with the final approval of the
     * plan (see PlanningWindow), and for good once the budget envelope is received or the year closed.
     */
    public static function planningOpen(int $year): bool
    {
        return app(\App\Services\PlanningWindow::class)->isOpen($year);
    }

    /** Can new activities still be planned for this unit (through its directorate) in this year? */
    public static function planningOpenFor(int $year, ?int $unitId): bool
    {
        return $unitId === null ? self::planningOpen($year) : app(\App\Services\PlanningWindow::class)->isOpenFor($year, $unitId);
    }

    /** The same verdict as planningEndedBy, for the directorate of a unit. */
    public static function planningEndedByFor(int $year, ?int $unitId): ?string
    {
        return $unitId === null ? self::planningEndedBy($year) : app(\App\Services\PlanningWindow::class)->unitEndedBy($year, $unitId);
    }

    /** 'Finalized', 'Approved' (envelope received) or 'Closed' for a year whose planning has ended, null while it is open. */
    public static function planningEndedBy(int $year): ?string
    {
        return app(\App\Services\PlanningWindow::class)->endedBy($year);
    }

    /** The first year from this one on whose plan is still open: where new activities go. */
    public static function nextPlanningYear(): int
    {
        return app(\App\Services\PlanningWindow::class)->nextOpenYear();
    }

    /** @return array<int, string> status of the envelope of each year that has one, for labelling the year switcher */
    public static function yearStatuses(): array
    {
        return self::query()->pluck('status', 'fiscal_year')->mapWithKeys(fn ($s, $y) => [(int) $y => $s])->all();
    }

    /** Years the user can switch to: every year with data, plus this year and next. */
    public static function availableYears(): array
    {
        static $memo = null;
        if ($memo !== null && ! app()->runningUnitTests()) {
            return $memo;
        }

        $current = (int) date('Y');
        $years = Activity::query()->distinct()->pluck('fiscal_year')
            ->merge(self::query()->pluck('fiscal_year'))
            ->push($current, $current + 1)
            ->map(fn ($y) => (int) $y)->unique()->sortDesc()->values()->all();

        return $memo = $years;
    }

    private static function yearHasData(int $year): bool
    {
        return self::query()->where('fiscal_year', $year)->exists()
            || Activity::query()->where('fiscal_year', $year)->exists();
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
        return $query->withoutGlobalScope(ProductionScope::class)->where('is_test_data', true);
    }

    /**
     * Relationship: Fiscal envelope has many category allocations
     */
    public function categoryAllocations()
    {
        return $this->hasMany(FiscalEnvelopeCategoryAllocation::class)->chaperone('fiscalEnvelope');
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
