<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ScheduledReport extends Model
{
    protected $fillable = [
        'name', 'report_slug', 'filters', 'schedule',
        'recipient_emails', 'formats', 'last_sent_at',
        'last_status', 'last_error', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'filters'         => 'array',
            'recipient_emails'=> 'array',
            'formats'         => 'array',
            'last_sent_at'    => 'datetime',
            'is_active'       => 'boolean',
        ];
    }

    /**
     * Check if this schedule is due based on the schedule expression.
     * Supports: 'daily', 'weekly', 'monthly', or a cron-like day-based pattern.
     */
    public function isDue(): bool
    {
        if (! $this->is_active) return false;

        $lastSent = $this->last_sent_at;
        $now      = now();

        if (! $lastSent) return true; // Never sent — send now

        return match ($this->schedule) {
            'daily'   => $lastSent->lt($now->copy()->startOfDay()),
            'weekly'  => $lastSent->lt($now->copy()->subWeek()),
            'monthly' => $lastSent->lt($now->copy()->startOfMonth()),
            default   => false, // Custom cron handled by console command
        };
    }

    public function getRecipientsAttribute(): array
    {
        return $this->recipient_emails ?? [];
    }
}
