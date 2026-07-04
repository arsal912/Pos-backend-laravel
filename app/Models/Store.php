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

    /**
     * User-supplied registration fields only.
     * Excluded from $fillable (set via direct property assignment):
     *   - status:           server-controlled lifecycle field; allowing mass assignment
     *                       would let a crafted request self-activate billing state.
     *   - is_active:        derived from status by server-side logic only.
     *   - tenancy_db_name:  managed exclusively by stancl/tenancy internals; overwriting
     *                       via mass assignment could redirect the tenant context to the
     *                       wrong database.
     */
    protected $fillable = [
        'name',
        'slug',
        'business_type',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'logo',
        'whatsapp_number',
        'currency',
        'timezone',
        'trial_ends_at',
        'deletion_scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'trial_ends_at' => 'datetime',
            'deletion_scheduled_at' => 'datetime',
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
     * Result is cached for 5 minutes to avoid N+1 on every middleware check.
     */
    public function hasModuleAccess(string $moduleSlug): bool
    {
        $resolve = function () use ($moduleSlug) {
            $storeModule = $this->storeModules()
                ->whereHas('module', fn ($q) => $q->where('slug', $moduleSlug))
                ->first();

            if ($storeModule) {
                return (bool) $storeModule->is_enabled;
            }

            return $this->activeSubscription?->plan?->hasModule($moduleSlug) ?? false;
        };

        // Tenancy auto-tags cache calls per-tenant; tag-less stores (database, file)
        // throw on ->tags(), so fall back to an uncached lookup instead of a 500.
        $cacheKey = "module_access:store:{$this->id}:{$moduleSlug}";
        try {
            return (bool) cache()->remember($cacheKey, 300, $resolve);
        } catch (\Throwable) {
            return (bool) $resolve();
        }
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
