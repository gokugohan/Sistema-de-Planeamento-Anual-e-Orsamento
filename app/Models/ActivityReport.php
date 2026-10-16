<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** The narrative of one activity for one month or one quarter. Counts once Verified. */
class ActivityReport extends Model
{
    use Auditable;

    public const MONTH = 'month';

    public const QUARTER = 'quarter';

    public const RATINGS = ['On track', 'Partly', 'Off track'];

    protected $fillable = [
        'activity_id', 'period_type', 'period', 'results', 'constraints', 'reasons_not_met', 'corrective_measures',
        'rating', 'compiled_results', 'compiled_constraints', 'compiled_by', 'compiled_at', 'status', 'submitted_by', 'verified_by', 'verified_at', 'rejection_notes',
    ];

    protected $casts = ['verified_at' => 'datetime', 'compiled_at' => 'datetime'];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['Draft', 'Rejected'], true);
    }
}
