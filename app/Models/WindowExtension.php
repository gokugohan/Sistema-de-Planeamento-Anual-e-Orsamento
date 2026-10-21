<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A closed period of the calendar reopened, by an administrator, for one unit and the units below it. */
class WindowExtension extends Model
{
    use Auditable;

    protected $fillable = ['fiscal_year', 'period_type', 'period', 'directorate_id', 'closes_on', 'reason', 'created_by'];

    protected $casts = ['closes_on' => 'date'];

    public function directorate()
    {
        return $this->belongsTo(Directorate::class);
    }
}
