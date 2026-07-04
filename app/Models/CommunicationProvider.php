<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class CommunicationProvider extends Model
{
    protected $connection = 'mysql'; // always central DB

    protected $fillable = [
        'channel', 'provider_slug', 'name',
        'is_active', 'is_default_for_channel',
        'credentials', 'config', 'rate_limits',
        'test_recipient', 'sort_order',
    ];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'is_active'               => 'boolean',
            'is_default_for_channel'  => 'boolean',
            'config'                  => 'array',
            'rate_limits'             => 'array',
        ];
    }

    // Encrypt credentials at rest
    public function setCredentialsAttribute($value): void
    {
        if (is_array($value)) {
            $this->attributes['credentials'] = Crypt::encryptString(json_encode($value));
            return;
        }
        $this->attributes['credentials'] = $value;
    }

    public function getCredentialsAttribute($value): array
    {
        if (! $value) return [];
        try {
            return json_decode(Crypt::decryptString($value), true) ?? [];
        } catch (\Throwable) {
            return json_decode($value, true) ?? [];
        }
    }

    /** Ensure only one default per channel when setting as default. */
    public function setAsDefault(): void
    {
        static::where('channel', $this->channel)
            ->where('id', '!=', $this->id)
            ->update(['is_default_for_channel' => false]);

        $this->update(['is_default_for_channel' => true]);
    }

    /** Display-safe version — strips credentials. */
    public function toArraySafe(): array
    {
        return array_diff_key($this->toArray(), ['credentials' => null]);
    }
}
