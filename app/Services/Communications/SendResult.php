<?php

namespace App\Services\Communications;

final class SendResult
{
    public function __construct(
        public readonly bool   $success,
        public readonly ?string $providerMessageId = null,
        public readonly float  $cost = 0.0,
        public readonly ?string $error = null,
        public readonly array  $raw = [],
    ) {}

    public static function ok(string $providerMessageId, float $cost = 0.0, array $raw = []): self
    {
        return new self(true, $providerMessageId, $cost, null, $raw);
    }

    public static function fail(string $error, array $raw = []): self
    {
        return new self(false, null, 0.0, $error, $raw);
    }
}
