<?php

namespace App\Http\Controllers\Api\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Milon\Barcode\DNS1D;

/**
 * Renders real, scannable barcode images (SVG) for one or many products at
 * once, so the "Barcode Generator" page and the single-product print page
 * can both print labels a handheld/USB scanner can actually read — rather
 * than the barcode number as plain text.
 */
class BarcodeLabelController extends Controller
{
    use ApiResponse;

    public function generate(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'product_ids'   => 'required|array|min:1|max:200',
            'product_ids.*' => 'integer',
        ]);

        $products = Product::whereIn('id', $validated['product_ids'])->get();
        $barcode  = new DNS1D();

        $labels = $products->map(function (Product $product) use ($barcode) {
            [$code, $type] = $this->resolveCode($product);

            $svg = null;
            if ($code !== null) {
                try {
                    $svg = $barcode->getBarcodeSVG($code, $type, 2, 60, 'black', true);
                } catch (\Throwable) {
                    // Code doesn't fit the chosen symbology (e.g. non-numeric EAN13) — skip the image.
                    $svg = null;
                }
            }

            return [
                'product_id' => $product->id,
                'name'       => $product->name,
                'sku'        => $product->sku,
                'price'      => $product->selling_price,
                'code'       => $code,
                'type'       => $type,
                'svg'        => $svg,
            ];
        });

        return $this->successResponse(['labels' => $labels]);
    }

    /**
     * Prefer the product's own barcode when it's EAN-13-shaped (what most
     * retail scanners are tuned for); fall back to CODE128 — which encodes
     * any alphanumeric string — using the barcode field if set, else the SKU.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function resolveCode(Product $product): array
    {
        if ($product->barcode && preg_match('/^\d{12,13}$/', $product->barcode)) {
            return [$product->barcode, 'EAN13'];
        }

        if ($product->barcode) {
            return [$product->barcode, 'C128'];
        }

        if ($product->sku) {
            return [$product->sku, 'C128'];
        }

        return [null, null];
    }
}
