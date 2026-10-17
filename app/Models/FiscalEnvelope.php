<?php

namespace App\Models;

use App\Models\Scopes\ProductionScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Legacy. The plan is the centre of the application and there is no fiscal envelope any more: what an approved
 * activity may spend is what its approved version says (see ActivityVersions). The table is kept, untouched, so
 * that nothing recorded before is lost and sample data loaded by an older version can still be purged.
 */
class FiscalEnvelope extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope(new ProductionScope);
    }

    protected $guarded = [];

    public function categoryAllocations()
    {
        return $this->hasMany(FiscalEnvelopeCategoryAllocation::class);
    }
}
