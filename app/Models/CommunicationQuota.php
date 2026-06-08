<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationQuota extends Model
{
    protected $table = 'communication_quotas';

    protected $fillable = [
        'sms_daily_quota',      'sms_sent_today',      'sms_quota_resets_at',
        'email_daily_quota',    'email_sent_today',    'email_quota_resets_at',
        'whatsapp_daily_quota', 'whatsapp_sent_today', 'whatsapp_quota_resets_at',
    ];

    protected function casts(): array
    {
        return [
            'sms_quota_resets_at'       => 'datetime',
            'email_quota_resets_at'     => 'datetime',
            'whatsapp_quota_resets_at'  => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], [
            'sms_daily_quota'      => 100,
            'email_daily_quota'    => 1000,
            'whatsapp_daily_quota' => 50,
            'sms_quota_resets_at'       => now()->addDay()->startOfDay(),
            'email_quota_resets_at'     => now()->addDay()->startOfDay(),
            'whatsapp_quota_resets_at'  => now()->addDay()->startOfDay(),
        ]);
    }

    public function hasQuota(string $channel): bool
    {
        $sent  = (int) $this->{"{$channel}_sent_today"};
        $quota = (int) $this->{"{$channel}_daily_quota"};
        return $sent < $quota;
    }

    public function increment(string $channel): void
    {
        $this->increment("{$channel}_sent_today");
    }
}
