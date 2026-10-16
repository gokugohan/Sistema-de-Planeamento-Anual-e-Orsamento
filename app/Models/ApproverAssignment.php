<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** A named person for one step (prepare, certify or approve) of the approval chain of a unit. */
class ApproverAssignment extends Model
{
    use Auditable;

    public const PREPARE = 'prepare';

    public const CERTIFY = 'certify';

    public const APPROVE = 'approve';

    public const STEPS = [self::PREPARE, self::CERTIFY, self::APPROVE];

    protected $fillable = ['directorate_id', 'user_id', 'step', 'position', 'created_by'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function directorate()
    {
        return $this->belongsTo(Directorate::class);
    }
}
