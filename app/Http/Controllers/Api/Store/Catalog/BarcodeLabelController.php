<?php

namespace App\Http\Controllers\Api\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Product;
use App\Services\BarcodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Milon\Barcode\DNS1D;

/**
 * Renders real, scannable barcode images (SVG) for one or many products at
 * once, so the "Barcode Generator" page and the single-product print page
 * can both print labels a handheld/USB scanner can actually read — rather
 * than the barcode number as plain text. Any selected product that doesn't
 * already have a barcode gets a real one generated and saved on the spot,
 * so it's not just a print-time stand-in — the product now has it for good
 * (POS lookup, receipts, future prints all see the same code).
 */
class BarcodeLabelController extends Controller
{
    use ApiResponse;

    public function __construct(private BarcodeService $barcodes) {}

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

        // Printing an existing barcode is a read; generating a new one for a
        // product that doesn't have one is a write — only require the extra
        // permission when at least one selected product actually needs it.
        if ($products->contains(fn (Product $p) => ! $p->barcode) && ! $request->user()->can('edit-products')) {
            return $this->errorResponse('Generating a new barcode requires edit-products permission.', 403);
        }

        $renderer = new DNS1D();

        $labels = $products->map(function (Product $product) use ($renderer) {
            if (! $product->barcode) {
                $product->update(['barcode' => $this->barcodes->generateUniqueEan13()]);
            }

            [$code, $type] = preg_match('/^\d{12,13}$/', $product->barcode)
                ? [$product->barcode, 'EAN13']
                : [$product->barcode, 'C128']; // non-standard existing barcode (e.g. legacy alphanumeric SKU-style code)

            $svg = null;
            try {
                $svg = $renderer->getBarcodeSVG($code, $type, 2, 60, 'black', true);
            } catch (\Throwable) {
                // Code doesn't fit the chosen symbology — skip the image, code/type still returned.
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
}
