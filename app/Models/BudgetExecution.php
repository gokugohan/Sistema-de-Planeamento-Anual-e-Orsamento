<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** What one approved budget line committed and paid in one month. Counts once Verified. */
class BudgetExecution extends Model
{
    use Auditable;
    use HasFactory;

    public const DRAFT = 'Draft';

    public const SUBMITTED = 'Submitted';

    public const VERIFIED = 'Verified';

    public const REJECTED = 'Rejected';

    protected $fillable = [
        'line_item_id', 'month', 'quarter', 'committed', 'paid', 'status', 'notes',
        'submitted_by', 'verified_by', 'verified_at',
    ];

    protected $casts = ['verified_at' => 'datetime'];

    protected static function booted(): void
    {
        // the quarter is always the month's: reports and indexes work by quarter
        static::saving(function (BudgetExecution $e) {
            if ($e->month) {
                $e->quarter = (int) ceil($e->month / 3);
            }
        });
    }

    public function lineItem()
    {
        return $this->belongsTo(LineItem::class);
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifiedBy()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /** Only draft or returned entries can be changed; submitted and verified ones are not edited. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::REJECTED], true);
    }
}
