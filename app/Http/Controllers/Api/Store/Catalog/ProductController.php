<?php

namespace App\Http\Controllers\Api\Store\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\BarcodeService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private StockService $stock, private BarcodeService $barcodes) {}

    // -------------------------------------------------------------------------
    // Product list & show
    // -------------------------------------------------------------------------

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->can('view-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $query = Product::with(['category:id,name', 'brand:id,name', 'unit:id,name,short_code',
                                'primaryImage:id,product_id,path'])
            ->withCount('variants');

        if ($search = $request->input('search')) {
            // Use FULLTEXT for 3+ char queries, LIKE for short
            if (mb_strlen($search) >= 3) {
                $query->whereRaw(
                    'MATCH(name, sku, barcode) AGAINST(? IN BOOLEAN MODE)',
                    [$search . '*']
                );
            } else {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%")
                      ->orWhere('barcode', 'like', "%{$search}%");
                });
            }
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        if ($request->filled('brand_id')) {
            $query->where('brand_id', $request->input('brand_id'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        $sort      = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');
        $allowed   = ['name', 'sku', 'selling_price', 'created_at'];
        $query->orderBy(in_array($sort, $allowed) ? $sort : 'name', $direction === 'desc' ? 'desc' : 'asc');

        $products = $query->paginate($request->input('per_page', 20));

        // Batch-load stock to avoid N+1 (one query for all product IDs)
        $productIds = $products->getCollection()->pluck('id');
        $stockMap = \App\Models\InventoryItem::whereIn('product_id', $productIds)
            ->whereNull('variant_id')
            ->groupBy('product_id')
            ->selectRaw('product_id, SUM(COALESCE(quantity, 0)) as total_qty')
            ->pluck('total_qty', 'product_id');

        // Append total stock to each product
        $products->getCollection()->transform(function (Product $p) use ($stockMap) {
            $p->total_stock = $stockMap[$p->id] ?? 0;
            return $p;
        });

        return $this->paginatedResponse($products);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('view-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $product = Product::with([
            'category:id,name,slug',
            'brand:id,name',
            'unit:id,name,short_code',
            'taxRate:id,name,rate,is_inclusive',
            'variants',
            'images',
        ])->findOrFail($id);

        $product->total_stock = $product->totalStock();

        return $this->successResponse(['product' => $product]);
    }

    // -------------------------------------------------------------------------
    // Barcode lookup (fast, for POS scanning)
    // -------------------------------------------------------------------------

    public function lookup(Request $request): JsonResponse
    {
        $barcode = $request->input('barcode') ?? $request->input('sku');

        if (! $barcode) {
            return $this->errorResponse('barcode or sku parameter is required.', 422);
        }

        // Check variants first
        $variant = ProductVariant::where('barcode', $barcode)
            ->orWhere('sku', $barcode)
            ->with(['product.taxRate', 'product.unit'])
            ->whereHas('product', fn ($q) => $q->where('is_active', true))
            ->first();

        if ($variant) {
            return $this->successResponse([
                'type'    => 'variant',
                'variant' => $variant,
                'product' => $variant->product,
            ]);
        }

        $product = Product::where('barcode', $barcode)
            ->orWhere('sku', $barcode)
            ->where('is_active', true)
            ->with(['taxRate', 'unit', 'activeVariants'])
            ->first();

        if (! $product) {
            return $this->errorResponse('Product not found.', 404);
        }

        if ($product->isVariable() && $product->activeVariants->count() > 1) {
            return $this->successResponse([
                'type'     => 'variable',
                'product'  => $product,
                'variants' => $product->activeVariants,
            ]);
        }

        return $this->successResponse([
            'type'    => 'product',
            'product' => $product,
        ]);
    }

    // -------------------------------------------------------------------------
    // Create & Update
    // -------------------------------------------------------------------------

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('create-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $validated = $this->validateProduct($request);

        return DB::transaction(function () use ($validated, $request) {
            $validated['slug'] = $this->uniqueSlug($validated['name']);

            if (empty($validated['sku'])) {
                $validated['sku'] = $this->generateSku();
            }

            $product = Product::create($validated);

            // Create initial inventory record for simple products
            if ($product->isSimple() && $request->filled('initial_stock')) {
                $branchId = $request->input('branch_id', app('current_store')->branches()->first()?->id ?? 1);
                $this->stock->addStock(
                    $product->id, null, $branchId,
                    (float) $request->input('initial_stock'),
                    'initial', null, null,
                    (float) ($validated['cost_price'] ?? 0),
                    'Initial stock'
                );
            }

            return $this->successResponse(
                ['product' => $product->load(['category', 'brand', 'unit', 'taxRate'])],
                'Product created.',
                201
            );
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('edit-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $product   = Product::findOrFail($id);
        $validated = $this->validateProduct($request, $id);

        if (isset($validated['name']) && $validated['name'] !== $product->name) {
            $validated['slug'] = $this->uniqueSlug($validated['name'], $id);
        }

        $product->update($validated);

        return $this->successResponse(
            ['product' => $product->fresh(['category', 'brand', 'unit', 'taxRate', 'variants', 'images'])],
            'Product updated.'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->can('delete-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $product = Product::findOrFail($id);
        $product->delete();

        return $this->successResponse(null, 'Product deleted.');
    }

    // -------------------------------------------------------------------------
    // Variants
    // -------------------------------------------------------------------------

    public function storeVariant(Request $request, int $productId): JsonResponse
    {
        if (! $request->user()->can('edit-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $product = Product::findOrFail($productId);

        if ($product->isSimple()) {
            return $this->errorResponse('Simple products do not support variants.', 422);
        }

        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'sku'           => 'required|string|unique:product_variants,sku',
            'barcode'       => 'nullable|string|unique:product_variants,barcode',
            'attributes'    => 'nullable|array',
            'cost_price'    => 'required|numeric|min:0',
            'selling_price' => 'required|numeric|min:0',
            'is_active'     => 'sometimes|boolean',
            'sort_order'    => 'sometimes|integer',
        ]);

        $variant = $product->variants()->create($validated);

        return $this->successResponse(['variant' => $variant], 'Variant created.', 201);
    }

    public function updateVariant(Request $request, int $productId, int $variantId): JsonResponse
    {
        if (! $request->user()->can('edit-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $variant = ProductVariant::where('product_id', $productId)->findOrFail($variantId);
        $validated = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'sku'           => "sometimes|string|unique:product_variants,sku,{$variantId}",
            'barcode'       => "nullable|string|unique:product_variants,barcode,{$variantId}",
            'attributes'    => 'nullable|array',
            'cost_price'    => 'sometimes|numeric|min:0',
            'selling_price' => 'sometimes|numeric|min:0',
            'is_active'     => 'sometimes|boolean',
            'sort_order'    => 'sometimes|integer',
        ]);

        $variant->update($validated);

        return $this->successResponse(['variant' => $variant->fresh()], 'Variant updated.');
    }

    public function destroyVariant(Request $request, int $productId, int $variantId): JsonResponse
    {
        if (! $request->user()->can('delete-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $variant = ProductVariant::where('product_id', $productId)->findOrFail($variantId);
        $variant->delete();

        return $this->successResponse(null, 'Variant deleted.');
    }

    // -------------------------------------------------------------------------
    // Images
    // -------------------------------------------------------------------------

    public function uploadImage(Request $request, int $productId): JsonResponse
    {
        if (! $request->user()->can('edit-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $request->validate([
            'image'      => 'required|image|max:5120',  // 5 MB max
            'variant_id' => 'nullable|exists:product_variants,id',
            'is_primary' => 'sometimes|boolean',
        ]);

        $product = Product::findOrFail($productId);

        $path = Storage::disk('local')->put(
            "products/{$productId}",
            $request->file('image')
        );

        $isPrimary = filter_var($request->input('is_primary', false), FILTER_VALIDATE_BOOLEAN);

        if ($isPrimary) {
            // Demote any existing primary
            ProductImage::where('product_id', $productId)->update(['is_primary' => false]);
        }

        $sortOrder = ProductImage::where('product_id', $productId)->max('sort_order') + 1;

        $image = ProductImage::create([
            'product_id' => $productId,
            'variant_id' => $request->input('variant_id'),
            'path'       => $path,
            'alt_text'   => $product->name,
            'sort_order' => $sortOrder,
            'is_primary' => $isPrimary,
        ]);

        return $this->successResponse(['image' => $image], 'Image uploaded.', 201);
    }

    public function destroyImage(Request $request, int $productId, int $imageId): JsonResponse
    {
        if (! $request->user()->can('edit-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $image = ProductImage::where('product_id', $productId)->findOrFail($imageId);
        Storage::disk('local')->delete($image->path);
        $image->delete();

        return $this->successResponse(null, 'Image deleted.');
    }

    // -------------------------------------------------------------------------
    // Barcode generation
    // -------------------------------------------------------------------------

    public function generateBarcode(Request $request, int $productId): JsonResponse
    {
        if (! $request->user()->can('edit-products')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $product = Product::findOrFail($productId);
        $barcode = $this->barcodes->generateUniqueEan13();

        $product->update(['barcode' => $barcode]);

        return $this->successResponse(['barcode' => $barcode, 'product' => $product->fresh()]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function validateProduct(Request $request, ?int $productId = null): array
    {
        $skuUnique = $productId
            ? "sometimes|string|max:100|unique:products,sku,{$productId}"
            : 'sometimes|string|max:100|unique:products,sku';

        $barcodeUnique = $productId
            ? "nullable|string|max:100|unique:products,barcode,{$productId}"
            : 'nullable|string|max:100|unique:products,barcode';

        return $request->validate([
            'name'                 => ($productId ? 'sometimes' : 'required') . '|string|max:255',
            'sku'                  => $skuUnique,
            'barcode'              => $barcodeUnique,
            'description'          => 'nullable|string',
            'type'                 => 'sometimes|in:simple,variable',
            'category_id'          => 'nullable|exists:categories,id',
            'brand_id'             => 'nullable|exists:brands,id',
            'unit_id'              => 'nullable|exists:units,id',
            'tax_rate_id'          => 'nullable|exists:tax_rates,id',
            'cost_price'           => 'sometimes|numeric|min:0',
            'selling_price'        => 'sometimes|numeric|min:0',
            'msrp'                 => 'nullable|numeric|min:0',
            'track_stock'          => 'sometimes|boolean',
            'allow_negative_stock' => 'sometimes|boolean',
            'low_stock_threshold'  => 'nullable|integer|min:0',
            'is_weightable'        => 'sometimes|boolean',
            'is_active'            => 'sometimes|boolean',
        ]);
    }

    private function uniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base  = Str::slug($name);
        $slug  = $base;
        $i     = 1;
        while (Product::withTrashed()
            ->where('slug', $slug)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->exists()
        ) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function generateSku(): string
    {
        do {
            $sku = 'PRD-' . strtoupper(Str::random(8));
        } while (Product::where('sku', $sku)->exists());
        return $sku;
    }

}
