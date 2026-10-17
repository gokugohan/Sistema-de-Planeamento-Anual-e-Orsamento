<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EconomicClassification extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'category_type'];

    public function lineItems()
    {
        return $this->hasMany(LineItem::class);
    }
}
