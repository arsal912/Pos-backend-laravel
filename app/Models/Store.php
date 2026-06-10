<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\StoreAggregate;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasInternalKeys;
use Stancl\Tenancy\Database\Concerns\TenantRun;

class Store extends Model implements TenantWithDatabase
{
    use HasFactory;
    use HasDatabase;
    use HasInternalKeys;
    use CentralConnection;
    use TenantRun;

    protected $fillable = [
        'name',
        'slug',
        'tenancy_db_name', // set by stancl/tenancy when the tenant DB is created
        'business_type',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'logo',
        'currency',
        'timezone',
        'status', // pending, active, suspended, expired
        'is_active',
        'trial_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trial_ends_at' => 'datetime',
        ];
    }

    public function getTenantKeyName(): string
    {
        return $this->getKeyName();
    }

    public function getTenantKey()
    {
        return $this->getKey();
    }

    /**
     * All users belonging to this store.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Store branches.
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /**
     * Active subscription for this store.
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', 'active')->latestOfMany();
    }

    public function aggregate(): HasOne
    {
        return $this->hasOne(StoreAggregate::class);
    }

    /**
     * All subscriptions (history).
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Modules enabled/disabled for this store.
     */
    public function storeModules(): HasMany
    {
        return $this->hasMany(StoreModule::class);
    }

    /**
     * Check if a specific module is enabled for this store.
     */
    public function hasModuleAccess(string $moduleSlug): bool
    {
        $storeModule = $this->storeModules()
            ->whereHas('module', fn ($q) => $q->where('slug', $moduleSlug))
            ->first();

        if ($storeModule) {
            return (bool) $storeModule->is_enabled;
        }

        // If no explicit setting, check the plan's default modules
        return $this->activeSubscription?->plan?->hasModule($moduleSlug) ?? false;
    }

    /**
     * Is the store currently in trial?
     */
    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    /**
     * Is the store's subscription active?
     */
    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription !== null;
    }
}
