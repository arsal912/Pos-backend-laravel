<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReceiptTemplate extends Model
{
    protected $fillable = [
        'name', 'type', 'header_text', 'footer_text',
        'show_logo', 'show_tax_breakdown', 'show_qr_code',
        'custom_css', 'is_default', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'show_logo'          => 'boolean',
            'show_tax_breakdown' => 'boolean',
            'show_qr_code'       => 'boolean',
            'is_default'         => 'boolean',
            'is_active'          => 'boolean',
        ];
    }

    /** Ensure only one default per type */
    public function setAsDefault(): void
    {
        static::where('type', $this->type)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
