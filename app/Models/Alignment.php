<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A national objective a programme or subprogramme contributes to: a Sustainable Development Goal, the strategic plan, or another. */
class Alignment extends Model
{
    use Auditable;
    use HasFactory;

    public const FRAMEWORKS = ['SDG', 'PED', 'Other'];

    protected $fillable = ['alignable_type', 'alignable_id', 'framework', 'reference', 'description'];

    public function alignable()
    {
        return $this->morphTo();
    }
}
