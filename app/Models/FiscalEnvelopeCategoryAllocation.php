<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FiscalEnvelopeCategoryAllocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'fiscal_envelope_id',
        'economic_classification_id',
        'allocated_amount',
        'planned_amount',
        'committed_amount',
        'spent_amount',
        'variance_amount',
        'variance_percentage',
        'variance_status',
        'approval_status',
        'approval_notes'
    ];

    protected function casts(): array
    {
        return [
            'allocated_amount' => 'decimal:2',
            'planned_amount' => 'decimal:2',
            'committed_amount' => 'decimal:2',
            'spent_amount' => 'decimal:2',
            'variance_amount' => 'decimal:2',
            'variance_percentage' => 'decimal:2',
        ];
    }

    /**
     * Relationship: Belongs to fiscal envelope
     */
    public function fiscalEnvelope()
    {
        return $this->belongsTo(FiscalEnvelope::class);
    }

    /**
     * Relationship: Belongs to economic classification
     */
    public function economicClassification()
    {
        return $this->belongsTo(EconomicClassification::class);
    }

    /**
     * Calculate variance and update status
     */
    public function calculateVariance(): void
    {
        $this->syncCommittedAmount();

        $this->variance_amount = (float) $this->allocated_amount - (float) $this->planned_amount;

        if ((float) $this->planned_amount > 0) {
            $this->variance_percentage = ((float) $this->variance_amount / (float) $this->planned_amount) * 100;
        } else {
            $this->variance_percentage = (float) $this->allocated_amount > 0 ? 100.0 : 0.0;
        }

        // Tolerance: ±0.01 (1 cent) considered balanced
        if (abs((float) $this->variance_amount) <= 0.01) {
            $this->variance_status = 'balanced';
        } elseif ((float) $this->variance_amount > 0) {
            $this->variance_status = 'over_allocated';
        } else {
            $this->variance_status = 'under_allocated';
        }

        $this->save();
    }

    /**
     * Sync committed amount from approved budget proposals
     */
    public function syncCommittedAmount(): void
    {
        $this->committed_amount = AdjustedBudget::whereHas('lineItem', function ($query) {
            $query->where('economic_classification_id', $this->economic_classification_id)
                ->whereHas('activity', function ($q) {
                    $q->where('fiscal_year', $this->fiscalEnvelope->fiscal_year);
                });
        })->sum('approved_amount');
    }

    /**
     * Static helper: Calculate planned amount from budget proposals
     * 
     * @param int $fiscalYear
     * @param int $economicClassificationId
     * @return float
     */
    public static function calculatePlannedAmount(int $fiscalYear, int $economicClassificationId): float
    {
        return LineItem::whereHas('activity', function ($query) use ($fiscalYear) {
            $query->where('fiscal_year', $fiscalYear);
        })
            ->where('economic_classification_id', $economicClassificationId)
            ->sum('estimated_amount');
    }

    /**
     * Get variance status badge color class
     */
    public function getVarianceStatusColorAttribute(): string
    {
        return match ($this->variance_status) {
            'balanced' => 'green',
            'over_allocated' => 'blue',
            'under_allocated' => 'orange',
            default => 'gray'
        };
    }

    /**
     * Get variance status label
     */
    public function getVarianceStatusLabelAttribute(): string
    {
        return match ($this->variance_status) {
            'balanced' => '✓ Balanced',
            'over_allocated' => '✅ Over-allocated',
            'under_allocated' => '⚠️ Under-allocated',
            default => 'Unknown'
        };
    }

    /**
     * Get approval status badge color class
     */
    public function getApprovalStatusColorAttribute(): string
    {
        return match ($this->approval_status) {
            'approved' => 'green',
            'pending_approval' => 'yellow',
            'rejected' => 'red',
            'draft' => 'gray',
            default => 'gray'
        };
    }
}
