<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    use HasFactory;

    protected $connection = 'mysql'; // always central DB

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category', // core, products, inventory, people, operations, reports, settings, advanced
        'icon',
        'is_active',     // available system-wide
        'is_core',       // cannot be disabled even by super admin
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_core' => 'boolean',
        ];
    }

    public function storeModules(): HasMany
    {
        return $this->hasMany(StoreModule::class);
    }

    public function userModules(): HasMany
    {
        return $this->hasMany(UserModule::class);
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(Plan::class, 'plan_modules');
    }
}
