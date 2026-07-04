<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CommunicationProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            // ── SMS ──────────────────────────────────────────────────────────
            [
                'channel'                => 'sms',
                'provider_slug'          => 'twilio-sms',
                'name'                   => 'Twilio SMS',
                'is_active'              => false,
                'is_default_for_channel' => true,
                'config'                 => json_encode([
                    'from_number'       => '+1234567890',
                    'messaging_service' => null,
                    'region'            => 'US',
                ]),
                'rate_limits' => json_encode(['messages_per_second' => 1]),
                'sort_order'  => 1,
            ],
            [
                'channel'                => 'sms',
                'provider_slug'          => 'local-pk-sms',
                'name'                   => 'Pakistani Local SMS (Stub)',
                'is_active'              => false,
                'is_default_for_channel' => false,
                'config'                 => json_encode(['sender_id' => 'POSAPP']),
                'rate_limits' => json_encode(['messages_per_second' => 5]),
                'sort_order'  => 2,
            ],

            // ── Email ─────────────────────────────────────────────────────────
            [
                'channel'                => 'email',
                'provider_slug'          => 'resend',
                'name'                   => 'Resend',
                'is_active'              => false,
                'is_default_for_channel' => true,
                'config'                 => json_encode([
                    'from_email' => 'noreply@yourstore.com',
                    'from_name'  => 'POS System',
                ]),
                'rate_limits' => json_encode(['emails_per_second' => 10]),
                'sort_order'  => 1,
            ],

            // ── WhatsApp ─────────────────────────────────────────────────────
            [
                'channel'                => 'whatsapp',
                'provider_slug'          => 'twilio-whatsapp',
                'name'                   => 'Twilio WhatsApp',
                'is_active'              => false,
                'is_default_for_channel' => true,
                'config'                 => json_encode([
                    'whatsapp_from' => 'whatsapp:+14155238886', // Twilio sandbox default
                    'sandbox_mode'  => true,
                ]),
                'rate_limits' => json_encode(['messages_per_second' => 1]),
                'sort_order'  => 1,
            ],
        ];

        foreach ($providers as $provider) {
            DB::table('communication_providers')->updateOrInsert(
                ['channel' => $provider['channel'], 'provider_slug' => $provider['provider_slug']],
                array_merge($provider, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
