<?php

namespace App\Services\Communications;

use App\Contracts\Communications\EmailProviderInterface;
use App\Contracts\Communications\SmsProviderInterface;
use App\Contracts\Communications\WhatsAppProviderInterface;
use App\Models\CommunicationProvider;
use App\Services\Communications\Providers\LocalPkSmsProvider;
use App\Services\Communications\Providers\ResendEmailProvider;
use App\Services\Communications\Providers\TwilioSmsProvider;
use App\Services\Communications\Providers\TwilioWhatsAppProvider;
use Illuminate\Support\Facades\Cache;

/**
 * Resolves the active provider for each channel from central DB config.
 * All tenants share platform-level provider credentials (D2).
 */
class CommunicationsManager
{
    // ── Channel resolvers ────────────────────────────────────────────────────

    public function sms(): SmsProviderInterface
    {
        return $this->resolve('sms');
    }

    public function email(): EmailProviderInterface
    {
        return $this->resolve('email');
    }

    public function whatsapp(): WhatsAppProviderInterface
    {
        return $this->resolve('whatsapp');
    }

    // ── Generic get by slug ──────────────────────────────────────────────────

    public function make(string $channel, ?string $slug = null): SmsProviderInterface|EmailProviderInterface|WhatsAppProviderInterface
    {
        $provider = $slug
            ? CommunicationProvider::where('channel', $channel)->where('provider_slug', $slug)->first()
            : CommunicationProvider::where('channel', $channel)->where('is_default_for_channel', true)->where('is_active', true)->first();

        if (! $provider) {
            throw new \RuntimeException("No active provider for channel '{$channel}'.");
        }

        return $this->instantiate($provider->provider_slug, $provider->credentials ?? []);
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private function resolve(string $channel): SmsProviderInterface|EmailProviderInterface|WhatsAppProviderInterface
    {
        // Cache provider config for 10 minutes to avoid DB hit per message
        $key = "comm_provider:{$channel}";

        $providerData = Cache::remember($key, 600, function () use ($channel) {
            $p = CommunicationProvider::where('channel', $channel)
                ->where('is_default_for_channel', true)
                ->where('is_active', true)
                ->first();

            if (! $p) return null;

            return ['slug' => $p->provider_slug, 'credentials' => $p->credentials ?? []];
        });

        if (! $providerData) {
            throw new \RuntimeException(
                "No active default provider configured for channel '{$channel}'. " .
                "Configure one in Admin → Communications Providers."
            );
        }

        return $this->instantiate($providerData['slug'], $providerData['credentials']);
    }

    private function instantiate(string $slug, array $credentials): SmsProviderInterface|EmailProviderInterface|WhatsAppProviderInterface
    {
        return match ($slug) {
            'twilio-sms'        => new TwilioSmsProvider($credentials),
            'twilio-whatsapp'   => new TwilioWhatsAppProvider($credentials),
            'resend'            => new ResendEmailProvider($credentials),
            'local-pk-sms'      => new LocalPkSmsProvider($credentials),
            default             => throw new \InvalidArgumentException("Unknown provider slug: {$slug}"),
        };
    }

    /** Flush the cached provider config (called after admin updates). */
    public function flushCache(string $channel): void
    {
        Cache::forget("comm_provider:{$channel}");
    }
}
