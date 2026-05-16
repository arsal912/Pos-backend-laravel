<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandingPageSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'is_enabled',                 // master toggle for entire landing page
        'maintenance_message',
        'site_title',
        'site_description',
        'meta_keywords',
        'og_image',
        'favicon',
        'logo',
        'primary_color',
        'redirect_when_disabled',     // url to redirect to when disabled
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    public function sections(): HasMany
    {
        return $this->hasMany(LandingPageSection::class, 'setting_id');
    }

    /**
     * Get the singleton settings record (creates if missing).
     */
    public static function current(): self
    {
        return static::firstOrCreate(
            ['id' => 1],
            [
                'is_enabled' => true,
                'site_title' => 'POS System',
                'site_description' => 'Multi-tenant Point of Sale platform',
                'primary_color' => '#4F46E5',
            ]
        );
    }
}
