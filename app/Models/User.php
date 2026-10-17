<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use Auditable;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'must_change_password',
        'directorate_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            // Never stored in clear text; never serialised (see $hidden).
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function directorate()
    {
        return $this->belongsTo(Directorate::class);
    }

    /**
     * Check if user is an administrator
     */
    public function isAdministrator(): bool
    {
        return $this->hasRole('Admin');
    }

    /**
     * Check if user is a minister
     */
    public function isMinister(): bool
    {
        return $this->hasRole('Minister');
    }

    /**
     * Does this user act as a director: by role, or because they stand in for one right now?
     */
    public function isDirector(): bool
    {
        return $this->hasRole('Director') || $this->seats()->isNotEmpty();
    }

    /** @var \Illuminate\Support\Collection<int, Directorate>|null */
    private ?\Illuminate\Support\Collection $seatCache = null;

    /**
     * The units this user directs: their own when they hold the Director role, and the units they stand in
     * for today (an interim appointment). Each seat gives authority over the unit and the units below it.
     */
    public function seats(): \Illuminate\Support\Collection
    {
        return $this->seatCache ??= Directorate::whereIn('id', array_filter([
            $this->hasRole('Director') ? $this->directorate_id : null,
            ...ApprovalDelegation::active()->where('user_id', $this->id)->pluck('directorate_id')->all(),
        ]))->get();
    }

    /** The unit ids this user directs: their seats and everything below them. */
    public function directedUnitIds(): array
    {
        return $this->seats()->flatMap(fn (Directorate $d) => $d->getSelfAndDescendantIds())->unique()->values()->all();
    }

    /** The seat that directs this unit most closely (the deepest one that contains it), if any. */
    public function seatFor(?Directorate $unit): ?Directorate
    {
        if (! $unit) {
            return null;
        }

        return $this->seats()->filter(fn (Directorate $d) => in_array($unit->id, $d->getSelfAndDescendantIds(), true))
            ->sortBy(fn (Directorate $d) => count($d->getSelfAndDescendantIds()))->first();
    }

    public function forgetSeats(): void
    {
        $this->seatCache = null;
        $this->interimCache = null;
    }

    /** @var array<int, int>|null */
    private ?array $interimCache = null;

    /** The unit ids (and the units below them) this user stands in for today, by an interim appointment only. @return array<int, int> */
    public function interimUnitIds(): array
    {
        return $this->interimCache ??= ApprovalDelegation::active()->where('user_id', $this->id)->pluck('directorate_id')
            ->flatMap(fn ($id) => Directorate::find($id)?->getSelfAndDescendantIds() ?? [])->unique()->values()->all();
    }

    /**
     * Check if user is a technical officer of a unit (the role that prepares its activities)
     */
    public function isTechnicalOfficer(): bool
    {
        return $this->hasRole('Planning');
    }
}
