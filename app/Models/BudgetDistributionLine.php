<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The amount of one economic category given to one top-level unit by a distribution; no unit: kept by the institution. */
class BudgetDistributionLine extends Model
{
    protected $fillable = ['budget_distribution_id', 'directorate_id', 'economic_classification_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function distribution()
    {
        return $this->belongsTo(BudgetDistribution::class, 'budget_distribution_id');
    }

    public function directorate()
    {
        return $this->belongsTo(Directorate::class);
    }
}
