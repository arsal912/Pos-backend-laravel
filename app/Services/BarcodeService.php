<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Generates real, unique EAN-13 barcode numbers. Shared by
 * ProductController::generateBarcode() (manual, single product) and
 * BarcodeLabelController (auto-assigns one to any selected product that
 * doesn't have one yet, before printing its labels).
 */
class BarcodeService
{
    public function generateUniqueEan13(): string
    {
        do {
            $digits = '';
            for ($i = 0; $i < 12; $i++) {
                $digits .= random_int(0, 9);
            }
            // EAN-13 check digit
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $digits[$i] * ($i % 2 === 0 ? 1 : 3);
            }
            $checkDigit = (10 - ($sum % 10)) % 10;
            $barcode    = $digits . $checkDigit;
        } while (
            Product::where('barcode', $barcode)->exists() ||
            ProductVariant::where('barcode', $barcode)->exists()
        );

        return $barcode;
    }
}
