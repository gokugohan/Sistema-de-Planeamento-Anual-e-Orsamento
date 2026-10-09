<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Indicator extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'activity_id',
        'name',
        'type',
        'unit',
        'annual_target',
    ];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function progress()
    {
        return $this->hasMany(IndicatorProgress::class);
    }
}
