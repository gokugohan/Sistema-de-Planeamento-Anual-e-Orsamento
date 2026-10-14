<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Scopes\ProductionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LineItem extends Model
{
    use Auditable;
    use HasFactory;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new ProductionScope);

        static::updating(function ($item) {
            if ($item->isLocked()) {
                throw new \Exception(__('This line item is locked because the planning cycle is finalized.'));
            }
        });

        static::deleting(function ($item) {
            if ($item->isLocked()) {
                throw new \Exception(__('This line item is locked because the planning cycle is finalized.'));
            }
        });
    }

    public function isLocked(): bool
    {
        return $this->activity && $this->activity->isLocked();
    }

    protected $fillable = [
        'activity_id',
        'code',
        'economic_classification_id',
        'account_code',
        'estimated_amount',
        'is_test_data',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
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
