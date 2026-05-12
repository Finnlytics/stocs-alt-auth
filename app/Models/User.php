<?php

namespace App\Models;

use App\Enums\Platform;
use App\Enums\PlatformRole;
use App\Enums\PlatformStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'email',
        'phone',
        'password',
        'is_super_admin',
        'marketing_preferences',
        'gdpr_consent_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'marketing_preferences' => 'array',
            'gdpr_consent_at' => 'datetime',
            'gdpr_data_deleted_at' => 'datetime',
        ];
    }

    public function platforms(): HasMany
    {
        return $this->hasMany(UserPlatform::class);
    }

    public function otpTokens(): HasMany
    {
        return $this->hasMany(OtpToken::class);
    }

    public function platformAccess(Platform $platform): ?UserPlatform
    {
        return $this->platforms()->where('platform', $platform->value)->first();
    }

    public function hasPlatformAccess(Platform $platform): bool
    {
        $access = $this->platformAccess($platform);

        return $access && $access->status === PlatformStatus::APPROVED->value;
    }

    public function isAdminOn(Platform $platform): bool
    {
        $access = $this->platformAccess($platform);

        return $access && $access->role === PlatformRole::ADMIN->value;
    }

    public function isAdmin(): bool
    {
        return $this->platforms()
            ->where('role', PlatformRole::ADMIN->value)
            ->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && $this->is_super_admin;
    }
}
