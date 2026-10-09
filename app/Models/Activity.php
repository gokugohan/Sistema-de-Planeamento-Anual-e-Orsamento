<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Scopes\ProductionScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use Auditable;
    use HasFactory;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new ProductionScope);

        static::updating(function ($activity) {
            if ($activity->isLocked()) {
                throw new \Exception(__('This activity is locked because the planning cycle is finalized.'));
            }
        });

        static::deleting(function ($activity) {
            if ($activity->isLocked()) {
                throw new \Exception(__('This activity is locked because the planning cycle is finalized.'));
            }
        });
    }

    public function isLocked(): bool
    {
        $envelope = FiscalEnvelope::where('fiscal_year', $this->fiscal_year)->first();

        return $envelope && $envelope->status === FiscalEnvelope::STATUS_CLOSED;
    }

    const STATUS_DRAFT = 'Draft';

    const STATUS_SUBMITTED = 'Submitted';

    const STATUS_REVIEWED = 'Reviewed';

    const STATUS_VALIDATED = 'Validated';

    const STATUS_WAITING_APPROVAL = 'Waiting Approval';

    const STATUS_Approved = 'Approved'; // Keeping lowercase 'Approved' if used elsewhere or correcting case? The file has 'Approved'

    const STATUS_APPROVED = 'Approved';

    const STATUS_REJECTED = 'Rejected';

    protected $fillable = [
        'program_id',
        'code',
        'directorate_id',
        'description',
        'manager_name',
        'quarter',
        'priority',
        'planned_start',
        'planned_end',
        'status',
        'procurement_required',
        'fiscal_year',
        'submitted_at',
        'reviewed_at',
        'approved_at',
        'reviewed_by',
        'approved_by',
        'is_test_data',
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function directorate()
    {
        return $this->belongsTo(Directorate::class);
    }

    public function indicators()
    {
        return $this->hasMany(Indicator::class);
    }

    public function lineItems()
    {
        return $this->hasMany(LineItem::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
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
