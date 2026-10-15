<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Where the money of a budget line comes from: the State Budget, a development partner, own revenue... */
class FundingSource extends Model
{
    use Auditable;

    public const KINDS = ['State', 'Partner', 'Other'];

    protected $fillable = ['code', 'name', 'kind', 'is_default', 'active'];

    protected $casts = ['is_default' => 'boolean', 'active' => 'boolean'];

    public function lineItems()
    {
        return $this->hasMany(LineItem::class);
    }

    /** The source a line gets when none is chosen. */
    public static function defaultId(): ?int
    {
        return static::where('is_default', true)->value('id') ?? static::where('active', true)->orderBy('id')->value('id');
    }

    /** The sources that can be chosen for a new line. */
    public static function choices()
    {
        return static::where('active', true)->orderByDesc('is_default')->orderBy('name')->get(['id', 'code', 'name']);
    }
}
