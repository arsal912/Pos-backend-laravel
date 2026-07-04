<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommunicationSetting extends Model
{
    protected $table = 'communication_settings';

    protected $fillable = [
        'sms_sender_id', 'email_from_address', 'email_from_name',
        'whatsapp_business_number', 'store_physical_address',
        'unsubscribe_landing_url',
    ];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
