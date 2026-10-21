<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** What a unit will do about an activity that is off track or partly on track, by whom and by when (an optional rule). */
class CorrectiveAction extends Model
{
    use Auditable;

    public const OPEN = 'Open';

    public const DONE = 'Done';

    protected $fillable = ['activity_id', 'quarter', 'description', 'owner_name', 'due_on', 'status', 'closing_note', 'closed_at', 'closed_by', 'created_by'];

    protected $casts = ['due_on' => 'date', 'closed_at' => 'datetime'];

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::OPEN && $this->due_on !== null && $this->due_on->lt(now()->startOfDay());
    }
}
