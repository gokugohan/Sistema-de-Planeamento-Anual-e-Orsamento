<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EconomicClassification extends Model
{
    use \App\Models\Concerns\Auditable;
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'category_type'];

    public function lineItems()
    {
        return $this->hasMany(LineItem::class);
    }


    /**
     * Relationship: Economic classification has many fiscal envelope allocations
     */
    public function fiscalEnvelopeAllocations()
    {
        return $this->hasMany(FiscalEnvelopeCategoryAllocation::class);
    }
}
