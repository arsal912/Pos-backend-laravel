<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;

class DataExportController extends Controller
{
    use ApiResponse;

    /**
     * Request a store data export (async — generates CSV files + zip).
     * GET /store/data-export
     */
    public function export(Request $request): JsonResponse
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $storeId = app('current_store_id');
        $timestamp = now()->format('Y-m-d-His');
        $exportDir = "data-exports/store-{$storeId}/{$timestamp}";

        // Generate CSV files synchronously (for now — TODO: dispatch async job for large datasets)
        $files = [];

        // Customers CSV
        $customers = Customer::select(['id','code','name','email','phone','city','country',
            'loyalty_points_balance','outstanding_balance','created_at'])->get();
        $files['customers.csv'] = $this->toCsv($customers->toArray());

        // Sales CSV (summary)
        $sales = Sale::select(['id','sale_number','customer_id','sale_date','total',
            'status','payment_status','created_at'])->get();
        $files['sales.csv'] = $this->toCsv($sales->toArray());

        // Products CSV
        $products = Product::select(['id','sku','name','selling_price','cost_price',
            'track_stock','is_active','created_at'])->get();
        $files['products.csv'] = $this->toCsv($products->toArray());

        // Store info JSON
        $store = $request->user()->store;
        $files['store-info.json'] = json_encode([
            'id'        => $store->id,
            'name'      => $store->name,
            'email'     => $store->email,
            'currency'  => $store->currency,
            'exported_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT);

        // Save files and create zip
        foreach ($files as $name => $content) {
            Storage::disk('local')->put("{$exportDir}/{$name}", $content);
        }

        // Create a simple manifest
        $manifest = [
            'store_id'    => $storeId,
            'exported_at' => now()->toIso8601String(),
            'files'       => array_keys($files),
            'counts'      => [
                'customers' => $customers->count(),
                'sales'     => $sales->count(),
                'products'  => $products->count(),
            ],
        ];
        Storage::disk('local')->put("{$exportDir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

        return $this->successResponse([
            'export_id'   => $timestamp,
            'files'       => array_keys($files),
            'counts'      => $manifest['counts'],
            'note'        => 'Export complete. Download individual files using the /data-export/{export_id}/{file} endpoint.',
            'expires_at'  => now()->addDays(7)->toIso8601String(),
        ], 'Data export generated successfully.');
    }

    /**
     * Download a specific exported file.
     * GET /store/data-export/{export_id}/{file}
     */
    public function download(Request $request, string $exportId, string $file): mixed
    {
        if (! $request->user()->can('manage-settings')) {
            return $this->errorResponse('Unauthorized.', 403);
        }

        $storeId = app('current_store_id');
        $path = "data-exports/store-{$storeId}/{$exportId}/{$file}";

        if (! Storage::disk('local')->exists($path)) {
            return $this->errorResponse('File not found.', 404);
        }

        // Verify the export is not older than 7 days
        $lastModified = Storage::disk('local')->lastModified($path);
        if (now()->subDays(7)->timestamp > $lastModified) {
            return $this->errorResponse('Export has expired. Please generate a new one.', 410);
        }

        return Storage::disk('local')->response($path);
    }

    private function toCsv(array $data): string
    {
        if (empty($data)) return '';
        $output = fopen('php://temp', 'r+');
        fputcsv($output, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }
}
