<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Scopes\ProductionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class LineItem extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes {
        performDeleteOnModel as protected deleteOrSetAside;
    }

    /**
     * A line that is part of the version in force (it has an amount in force) is not deleted when a revision removes
     * it: it only leaves the revision, and the amount in force stays until the revision is approved (ActivityVersions
     * then removes it for good). Every other line is deleted as usual.
     */
    protected function performDeleteOnModel()
    {
        if ($this->forceDeleting || $this->adjustedBudget()->exists()) {
            return $this->deleteOrSetAside();
        }

        $this->forceDeleting = true;
        try {
            return $this->deleteOrSetAside();
        } finally {
            $this->forceDeleting = false;
        }
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new ProductionScope);

        // every line has a source: the default one (State Budget) unless another is chosen
        static::creating(function ($item) {
            $item->funding_source_id ??= FundingSource::defaultId();
        });

        // the confirmation was of these lines: changing them asks for it again
        $reset = fn ($item) => Activity::whereKey($item->activity_id)->whereNotNull('budget_confirmed_at')
            ->update(['budget_confirmed_at' => null, 'budget_confirmed_by' => null]);
        static::saved($reset);
        static::deleted($reset);

        static::updating(function ($item) {
            if ($item->isLocked()) {
                throw new \Exception(__('This line item is locked because the planning cycle is finalized.'));
            }
        });

        static::deleting(function ($item) {
            if ($item->isLocked()) {
                throw new \Exception(__('This line item is locked because the planning cycle is finalized.'));
            }
            // spending reported against a line keeps it: a revision sets its amount instead of removing it
            if ($item->executions()->exists()) {
                throw new \DomainException(__('This budget line already has an executed amount reported against it: change its amount instead of removing it.'));
            }
        });
    }

    /** Nothing locks a line any more: an approved activity is changed through a revision (see ActivityVersions). */
    public function isLocked(): bool
    {
        return false;
    }

    protected $fillable = [
        'activity_id',
        'indicator_id',
        'code',
        'economic_classification_id',
        'funding_source_id',
        'account_code',
        'estimated_amount',
        'is_test_data',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    /** The indicator this line pays for, when the budget is allocated per indicator (otherwise it belongs to the whole activity). */
    public function indicator()
    {
        return $this->belongsTo(Indicator::class);
    }

    public function fundingSource()
    {
        return $this->belongsTo(FundingSource::class);
    }

    public function economicClassification()
    {
        return $this->belongsTo(EconomicClassification::class);
    }

    public function executions()
    {
        return $this->hasMany(BudgetExecution::class);
    }

    public function adjustedBudget()
    {
        return $this->hasOne(AdjustedBudget::class);
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
}
