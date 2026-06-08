<?php

namespace App\Services\Communications;

/**
 * Generates and verifies HMAC-signed unsubscribe URLs.
 *
 * The URL points to the frontend /unsubscribe page.
 * The frontend page calls POST /api/v1/unsubscribe/confirm with the same params.
 * This keeps the backend API-only while still being verifiable.
 *
 * Signature covers: channel + recipient + store_id (prevents forgery / scope-creep).
 */
class UnsubscribeUrl
{
    public static function generate(string $channel, string $recipient, int $storeId): string
    {
        $sig = self::sign($channel, $recipient, $storeId);

        return rtrim(config('app.frontend_url', 'http://localhost:3000'), '/').'/unsubscribe?'
            .http_build_query([
                'channel'  => $channel,
                'r'        => $recipient,
                'store'    => $storeId,
                'sig'      => $sig,
            ]);
    }

    public static function verify(string $channel, string $recipient, int $storeId, string $sig): bool
    {
        return hash_equals(self::sign($channel, $recipient, $storeId), $sig);
    }

    private static function sign(string $channel, string $recipient, int $storeId): string
    {
        return hash_hmac(
            'sha256',
            $channel.'|'.strtolower(trim($recipient)).'|'.$storeId,
            config('app.key')
        );
    }
}
