<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** The approved plan of a year as it stood at a moment: the version of each activity then in force (an optional rule). */
class PlanBaseline extends Model
{
    use Auditable;

    protected $fillable = ['fiscal_year', 'versions', 'activity_count', 'total', 'note', 'recorded_by', 'recorded_at'];

    protected $casts = ['versions' => 'array', 'recorded_at' => 'datetime', 'total' => 'decimal:2'];

    public function recorder()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
