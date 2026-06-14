<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppReportRequest extends Model
{
    protected $fillable = [
        'from_number', 'message', 'status',
        'report_type', 'date_from', 'date_to', 'period_label',
        'pdf_path', 'download_token', 'download_expires_at',
        'ai_response', 'error',
    ];

    protected function casts(): array
    {
        return [
            'date_from'           => 'date',
            'date_to'             => 'date',
            'download_expires_at' => 'datetime',
            'ai_response'         => 'array',
        ];
    }

    public function isExpired(): bool
    {
        return $this->download_expires_at && $this->download_expires_at->isPast();
    }
}
