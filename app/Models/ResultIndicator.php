<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A result indicator of a programme or subprogramme: the change it is meant to bring about, with its
 * baseline and target. It is described, not reported quarter by quarter (activity indicators are).
 */
class ResultIndicator extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = ['indicatable_type', 'indicatable_id', 'name', 'baseline', 'target', 'means_of_verification'];

    public function indicatable()
    {
        return $this->morphTo();
    }
}
