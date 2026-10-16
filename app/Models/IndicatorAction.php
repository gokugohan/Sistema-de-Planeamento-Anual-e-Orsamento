<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One numbered action of the implementation plan of an indicator, with the months it is carried out in. */
class IndicatorAction extends Model
{
    protected $fillable = ['indicator_id', 'position', 'text', 'months'];

    protected $casts = ['months' => 'array'];

    public function indicator()
    {
        return $this->belongsTo(Indicator::class);
    }

    /** @return array<int, int> the months (1..12), sorted */
    public function monthList(): array
    {
        $m = array_values(array_unique(array_filter(array_map('intval', (array) $this->months), fn ($n) => $n >= 1 && $n <= 12)));
        sort($m);

        return $m;
    }
}
