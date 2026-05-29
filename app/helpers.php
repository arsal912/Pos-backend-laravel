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
