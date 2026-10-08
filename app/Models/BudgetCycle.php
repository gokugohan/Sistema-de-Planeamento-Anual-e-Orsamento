<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetCycle extends Model
{
    use \App\Models\Concerns\Auditable;

    protected $fillable = [
        'fiscal_year',
        'indicative_ceiling', 'ceiling_reference', 'ceiling_date',
        'submission_reference', 'submission_fingerprint', 'submitted_total', 'submitted_activities',
        'submitted_at', 'submitted_by',
        'cro_total', 'cro_decision_date', 'cro_reference', 'cro_notes', 'cro_recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'indicative_ceiling' => 'decimal:2',
            'submitted_total' => 'decimal:2',
            'cro_total' => 'decimal:2',
            'ceiling_date' => 'date',
            'cro_decision_date' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    public function submissionLines()
    {
        return $this->hasMany(BudgetCycleSubmissionLine::class);
    }

    public function submitter()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function envelope()
    {
        return $this->hasOne(FiscalEnvelope::class, 'fiscal_year', 'fiscal_year');
    }

    public function isSubmitted(): bool
    {
        return $this->submitted_at !== null;
    }

    public function hasCroDecision(): bool
    {
        return $this->cro_total !== null;
    }
}
