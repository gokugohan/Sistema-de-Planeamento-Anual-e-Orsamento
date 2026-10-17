<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** One signature of a list that signs in order: the listed person's turn on an activity, in a stage. */
class ActivitySignature extends Model
{
    use Auditable;

    protected $fillable = ['activity_id', 'stage', 'user_id', 'signed_by', 'signed_at'];

    protected $casts = ['signed_at' => 'datetime'];
}
