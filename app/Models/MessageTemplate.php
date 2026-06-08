<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageTemplate extends Model
{
    protected $fillable = [
        'name',
        'description',
        'channel',
        'subject',
        'body',
        'type',
        'variables',
        'is_active',
        'is_system',
        'whatsapp_template_name',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * Render body with provided variables, replacing {{key}} placeholders.
     */
    public function render(array $vars): string
    {
        $body = $this->body;
        foreach ($vars as $key => $value) {
            $body = str_replace('{{'.$key.'}}', (string) $value, $body);
            $body = str_replace('{{ '.$key.' }}', (string) $value, $body);
        }
        return $body;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForChannel($query, string $channel)
    {
        return $query->where('channel', $channel);
    }
}
