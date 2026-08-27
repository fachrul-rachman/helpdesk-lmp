<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'phone_number',
        'role',
        'division_id',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            if ($user->division_id) {
                $user->divisions()->syncWithoutDetaching([$user->division_id]);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function divisions(): BelongsToMany
    {
        return $this->belongsToMany(Division::class);
    }

    public function scopeInDivision(Builder $query, string $divisionId): Builder
    {
        return $query->where(function (Builder $query) use ($divisionId): void {
            $query->where('users.division_id', $divisionId)
                ->orWhereHas('divisions', fn (Builder $query) => $query->where('divisions.id', $divisionId));
        });
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
