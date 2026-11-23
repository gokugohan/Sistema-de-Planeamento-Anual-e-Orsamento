<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/** Where the money of a budget line comes from: the State Budget, a development partner, own revenue... */
class FundingSource extends Model
{
    use Auditable;

    public const KINDS = ['State', 'Partner', 'Other'];

    protected $fillable = ['code', 'name', 'kind', 'counts_in_title', 'is_default', 'active'];

    protected $casts = ['is_default' => 'boolean', 'active' => 'boolean', 'counts_in_title' => 'boolean'];

    /**
     * The sources whose money belongs to the institution's own budget title (the State Budget, by default): only these
     * are compared with a budget decision and its distribution. A partner's money or the Infrastructure Fund is another title.
     *
     * @return array<int, int>
     */
    public static function inTitle(): array
    {
        return static::where('counts_in_title', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * The sources of the Infrastructure Fund (Fundo das Infraestruturas), known by their code (FI) or name. Moves between
     * its programs are not held to the 20 % limit of a budget change (Decreto-Lei n.º 42/2025, art. 35.3, as amended by
     * Decreto-Lei n.º 26/2026).
     *
     * @return array<int, int>
     */
    public static function infrastructureFund(): array
    {
        return static::query()->get(['id', 'code', 'name'])
            ->filter(fn ($s) => strtoupper(trim((string) $s->code)) === 'FI' || preg_match('/infra-?e?strutura|infrastructure fund/i', (string) $s->name))
            ->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

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
