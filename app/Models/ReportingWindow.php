<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** The dates between which one month or one quarter of one fiscal year may be reported. */
class ReportingWindow extends Model
{
    use Auditable;

    protected $fillable = ['fiscal_year', 'period_type', 'period', 'opens_on', 'closes_on', 'note', 'created_by'];

    protected $casts = ['opens_on' => 'date', 'closes_on' => 'date'];
}
