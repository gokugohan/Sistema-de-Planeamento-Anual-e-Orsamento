<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** The middle level of the budget structure: a programme is divided into subprogrammes, which hold the activities. */
class Subprogram extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = ['program_id', 'code', 'name', 'objective'];

    protected static function booted(): void
    {
        static::deleting(function (Subprogram $subprogram) {
            $subprogram->alignments()->delete();
            $subprogram->resultIndicators()->delete();
        });
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function resultIndicators()
    {
        return $this->morphMany(ResultIndicator::class, 'indicatable');
    }

    public function alignments()
    {
        return $this->morphMany(Alignment::class, 'alignable');
    }
}
