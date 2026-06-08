<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    protected $fillable = [
        'name', 'description', 'channel', 'type',
        'message_template_id', 'subject', 'body', 'variables',
        'target_type', 'target_id', 'scheduled_at', 'status',
        'total_recipients', 'sent_count', 'failed_count', 'skipped_count',
        'created_by', 'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'variables'    => 'array',
            'scheduled_at' => 'datetime',
            'started_at'   => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function template()
    {
        return $this->belongsTo(MessageTemplate::class, 'message_template_id');
    }

    public function isSendable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }

    /** Delivery rate as a percentage (0-100). */
    public function deliveryRate(): float
    {
        if ($this->total_recipients === 0) return 0.0;
        return round(($this->sent_count / $this->total_recipients) * 100, 1);
    }
}
