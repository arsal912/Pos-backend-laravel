<?php

if (! function_exists('currency')) {
    function currency(float|string|null $amount, string $currency = 'USD'): string
    {
        $amount = (float) ($amount ?? 0);
        $currency = strtoupper($currency);

        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'PKR' => '₨',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';

        return $symbol . number_format($amount, 2);
    }
}

if (! function_exists('receipt_merge_tags')) {
    /**
     * Replace {{store.name}}-style merge tags in receipt header/footer text.
     * Unknown or unresolvable tags collapse to an empty string rather than
     * printing the raw {{tag}} on a real receipt.
     */
    function receipt_merge_tags(?string $text, array $context): ?string
    {
        if (! $text) {
            return $text;
        }

        return preg_replace_callback('/\{\{\s*([\w.]+)\s*\}\}/', function ($matches) use ($context) {
            $value = data_get($context, $matches[1]);

            return is_scalar($value) ? (string) $value : '';
        }, $text);
    }
}

if (! function_exists('receipt_logo_data_uri')) {
    /**
     * Inline a store logo (stored on the private 'local' disk) as a base64
     * data URI, so it renders in both print-preview windows and dompdf PDFs
     * without needing a public, authenticated, or otherwise network-fetchable URL.
     */
    function receipt_logo_data_uri(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($path)) {
            return null;
        }

        $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($disk->get($path));
    }
}
