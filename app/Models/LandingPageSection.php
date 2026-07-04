<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandingPageSection extends Model
{
    use HasFactory;

    protected $connection = 'mysql'; // always central DB

    protected $fillable = [
        'setting_id',
        'section_key',     // hero, features, pricing, testimonials, faq, footer, etc.
        'title',
        'subtitle',
        'content',         // json - flexible per section
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(LandingPageSetting::class, 'setting_id');
    }
}
