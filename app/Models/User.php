<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\EmailVerificationToken;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * Only user-supplied registration/profile fields belong here.
     * Server-controlled fields (is_super_admin, store_id, branch_id,
     * email_verified_at, last_login_at, last_login_ip, login_attempts,
     * locked_until) are intentionally excluded to prevent mass-assignment
     * privilege escalation. Set them via direct property assignment.
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the store this user belongs to.
     */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * Get the branch this user belongs to.
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Email verification tokens for the user.
     */
    public function emailVerificationTokens(): HasMany
    {
        return $this->hasMany(EmailVerificationToken::class);
    }

    /**
     * Modules specifically toggled for this user (overrides store-level).
     */
    public function userModules(): HasMany
    {
        return $this->hasMany(UserModule::class);
    }

    /**
     * Check if user is a super admin.
     */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /**
     * Check if user is a store owner.
     */
    public function isStoreOwner(): bool
    {
        return $this->hasRole('store-owner');
    }

    /**
     * Check if a specific module is enabled for this user.
     * Priority: user-level override > store-level setting > plan default.
     * Result is cached for 5 minutes to avoid N+1 on every middleware check.
     */
    public function hasModuleAccess(string $moduleSlug): bool
    {
        if ($this->isSuperAdmin()) return true;

        $cacheKey = "module_access:user:{$this->id}:{$moduleSlug}";
        return (bool) cache()->remember($cacheKey, 300, function () use ($moduleSlug) {
            $userModule = $this->userModules()
                ->whereHas('module', fn ($q) => $q->where('slug', $moduleSlug))
                ->first();

            if ($userModule) {
                return (bool) $userModule->is_enabled;
            }

            return $this->store?->hasModuleAccess($moduleSlug) ?? false;
        });
    }
}
