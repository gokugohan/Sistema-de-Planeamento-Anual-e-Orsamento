<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use \App\Models\Concerns\Auditable;
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
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
     * Check if user is a director
     */
    public function isDirector(): bool
    {
        return $this->hasRole('Director');
    }

    /**
     * Check if user is a planner
     */
    public function isPlanner(): bool
    {
        return $this->hasRole('Planning');
    }
}
