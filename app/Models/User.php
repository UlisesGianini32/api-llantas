<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, TwoFactorAuthenticatable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_OPERATIONS = 'operations';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_OPERATIONS];

    protected $fillable = [
        'name',
        'email',
        'password',
        'meli_id',
        'official_store_id',   // ✅ NUEVO
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'   => 'datetime',
            'password'            => 'hashed',
            'expires_at'          => 'datetime',
            'official_store_id'   => 'integer', // ✅ NUEVO
        ];
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    public function isOperations(): bool
    {
        return $this->hasRole(self::ROLE_OPERATIONS);
    }

    /** @return HasMany<MeliAccount, User> */
    public function meliAccounts(): HasMany
    {
        return $this->hasMany(MeliAccount::class);
    }

    /**
     * Mantiene users.meli_id / tokens alineados con la cuenta MeLi marcada como default (compatibilidad con jobs y APIs).
     */
    public function syncMeliColumnsFromDefaultAccount(): void
    {
        $acc = $this->meliAccounts()->where('is_default', true)->first()
            ?? $this->meliAccounts()->orderBy('id')->first();

        if (! $acc) {
            $this->forceFill([
                'meli_id' => null,
                'access_token' => null,
                'refresh_token' => null,
                'expires_at' => null,
                'official_store_id' => null,
            ])->save();

            return;
        }

        $this->forceFill([
            'meli_id' => $acc->meli_user_id,
            'access_token' => $acc->access_token,
            'refresh_token' => $acc->refresh_token,
            'expires_at' => $acc->expires_at,
            'official_store_id' => $acc->official_store_id,
        ])->save();
    }
}
