<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** One signature of a list that signs in order: the listed person's turn on an activity, in a stage. */
class ActivitySignature extends Model
{
    use Auditable;

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const VOIDED = 'voided';

    protected $fillable = ['activity_id', 'stage', 'round', 'user_id', 'signed_by', 'signed_at', 'state', 'closed_at', 'closed_by', 'close_reason'];

    protected $casts = ['signed_at' => 'datetime', 'closed_at' => 'datetime'];

    /** The round the next signature of this activity belongs to: one more than the last round that was voided. */
    public static function currentRound(int $activityId): int
    {
        return 1 + (int) static::where(['activity_id' => $activityId, 'state' => self::VOIDED])->max('round');
    }

    /**
     * Close the running signatures of an activity (all stages, or one) without deleting them: completed when the
     * activity moved on, voided when it was returned or reopened. Saved one by one so each change is audited.
     */
    public static function close(int $activityId, string $state, ?int $by = null, ?string $reason = null, ?string $stage = null): void
    {
        $rows = static::where(['activity_id' => $activityId, 'state' => self::ACTIVE])->when($stage, fn ($q) => $q->where('stage', $stage))->get();
        foreach ($rows as $row) {
            $row->auditMetadata = array_filter(['by' => $by, 'reason' => $reason, 'signature' => $state]);
            $row->update(['state' => $state, 'closed_at' => now(), 'closed_by' => $by, 'close_reason' => $reason]);
        }
    }
}
