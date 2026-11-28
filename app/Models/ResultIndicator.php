<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A result of a programme or subprogramme: the change it is meant to bring about, with its indicator, baseline and
 * target. It is described, not reported quarter by quarter (activity indicators are). In the State Budget's annual
 * plans (Anexo K) a programme's results are its outcomes (Objetivo de Médio Prazo) and a subprogramme's are its
 * outputs (Resultado de Curto Prazo); each output serves one outcome of its programme, and each activity one output.
 */
class ResultIndicator extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = ['indicatable_type', 'indicatable_id', 'code', 'statement', 'outcome_id', 'name', 'baseline', 'target', 'means_of_verification'];

    public function indicatable()
    {
        return $this->morphTo();
    }

    /** For an output: the outcome of the programme it serves. */
    public function outcome()
    {
        return $this->belongsTo(self::class, 'outcome_id');
    }

    /** For an outcome: the outputs that serve it. */
    public function outputs()
    {
        return $this->hasMany(self::class, 'outcome_id');
    }

    /** "502.0.1: Sistema de transporte…" or the indicator when there is no statement: how the result is named in a list. */
    public function label(): string
    {
        $text = $this->statement ?: $this->name;

        return $this->code ? "{$this->code}: {$text}" : (string) $text;
    }
}
