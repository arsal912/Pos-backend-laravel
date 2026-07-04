<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CampaignRecipient extends Model
{
    protected $fillable = [
        'campaign_id', 'customer_id', 'recipient', 'communication_log_id',
    ];

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function log()
    {
        return $this->belongsTo(CommunicationLog::class, 'communication_log_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
