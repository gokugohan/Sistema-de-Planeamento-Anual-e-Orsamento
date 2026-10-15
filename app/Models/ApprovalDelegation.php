<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** For a set time, a user stands in for the director of a unit (an interim or acting approver). */
class ApprovalDelegation extends Model
{
    use Auditable;

    protected $fillable = ['user_id', 'directorate_id', 'starts_on', 'ends_on', 'reason', 'created_by', 'revoked_at'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'revoked_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function directorate()
    {
        return $this->belongsTo(Directorate::class);
    }

    /** In force today: not revoked, and today is between the first and the last day. */
    public function scopeActive(Builder $query): Builder
    {
        $today = now()->toDateString();

        return $query->whereNull('revoked_at')->where('starts_on', '<=', $today)->where('ends_on', '>=', $today);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && now()->startOfDay()->between($this->starts_on, $this->ends_on);
    }
}
