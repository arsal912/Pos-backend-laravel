<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class PaymentGateway extends Model
{
    use HasFactory;

    protected $connection = 'mysql';

    protected $fillable = [
        'name',
        'slug',
        'logo',
        'credentials',
        'is_active',
        'is_test_mode',
        'supports_subscription',
        'supported_currencies',
        'sort_order',
    ];

    protected $casts = [
        'supported_currencies' => 'array',
        'is_active' => 'boolean',
        'is_test_mode' => 'boolean',
    ];

    protected $hidden = [
        'credentials',
    ];

    public function setCredentialsAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['credentials'] = Crypt::encryptString(json_encode($value));
            return;
        }

        if (is_string($value) && $value !== '') {
            $this->attributes['credentials'] = $value;
            return;
        }

        $this->attributes['credentials'] = null;
    }

    public function getCredentialsAttribute($value)
    {
        if (! $value) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($value), true) ?: [];
        } catch (\Throwable $e) {
            return json_decode($value, true) ?: [];
        }
    }
}
