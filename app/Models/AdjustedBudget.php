<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AdjustedBudget extends Model
{
    use Auditable;
    use HasFactory;

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::updating(function ($budget) {
            if ($budget->isLocked()) {
                throw new \Exception(__('This allocation is locked because the planning cycle is finalized.'));
            }
        });

        static::deleting(function ($budget) {
            if ($budget->isLocked()) {
                throw new \Exception(__('This allocation is locked because the planning cycle is finalized.'));
            }
        });
    }

    public function isLocked(): bool
    {
        return $this->lineItem && $this->lineItem->isLocked();
    }

    protected $fillable = [
        'line_item_id',
        'approved_amount',
        'status',
    ];

    public function lineItem()
    {
        return $this->belongsTo(LineItem::class);
    }
}
