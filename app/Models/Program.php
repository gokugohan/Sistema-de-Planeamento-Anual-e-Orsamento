<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = ['name', 'oge_code', 'manager_name', 'objective', 'kpis'];

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }
}
