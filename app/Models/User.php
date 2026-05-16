<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'store_id',
        'branch_id',
        'is_super_admin',
        'is_active',
        'last_login_at',
        'last_login_ip',
        'email_verified_at',
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
     */
    public function hasModuleAccess(string $moduleSlug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Check user-level override first
        $userModule = $this->userModules()
            ->whereHas('module', fn ($q) => $q->where('slug', $moduleSlug))
            ->first();

        if ($userModule) {
            return (bool) $userModule->is_enabled;
        }

        // Fallback to store-level
        return $this->store?->hasModuleAccess($moduleSlug) ?? false;
    }
}
