<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    use \App\Models\Concerns\Auditable;
    use HasFactory;

    protected $fillable = ['name', 'oge_code', 'manager_name', 'objective', 'kpis'];

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }


}
