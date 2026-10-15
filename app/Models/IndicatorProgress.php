<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IndicatorProgress extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'indicator_id',
        'quarter',
        'achieved_value',
        'status',
        'submitted_by',
        'verified_by',
        'verified_at',
        'rejection_notes',
    ];

    protected $casts = ['verified_at' => 'datetime'];

    public function indicator()
    {
        return $this->belongsTo(Indicator::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
