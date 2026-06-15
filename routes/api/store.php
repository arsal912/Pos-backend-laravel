<?php

use App\Http\Controllers\Api\Store\AccountController;
use App\Http\Controllers\Api\Store\BillingController;
use App\Http\Controllers\Api\Store\CommunicationSettingsController;
use App\Http\Controllers\Api\Store\DataExportController;
use App\Http\Controllers\Api\Store\CustomerController;
use App\Http\Controllers\Api\Store\ExpenseController;
use App\Http\Controllers\Api\Store\ReportController;
use App\Http\Controllers\Api\Store\ScheduledReportController;
use App\Http\Controllers\Api\Store\Crm\CommunicationController;
use App\Http\Controllers\Api\Store\Crm\CreditController;
use App\Http\Controllers\Api\Store\Crm\CustomerGroupController;
use App\Http\Controllers\Api\Store\Crm\CustomerNoteController;
use App\Http\Controllers\Api\Store\Crm\CustomerSegmentController;
use App\Http\Controllers\Api\Store\Crm\GroupPricingController;
use App\Http\Controllers\Api\Store\Crm\LoyaltyController;
use App\Http\Controllers\Api\Store\Crm\CampaignController;
use App\Http\Controllers\Api\Store\Crm\MessageTemplateController;
use App\Http\Controllers\Api\Store\Pos\DeviceController;
use App\Http\Controllers\Api\Store\Pos\PosController;
use App\Http\Controllers\Api\Store\Pos\OfflineSalesSyncController;
use App\Http\Controllers\Api\Store\Pos\PosSyncController;
use App\Http\Controllers\Api\Store\Pos\SaleController;
use App\Http\Controllers\Api\Store\ReceiptTemplateController;
use App\Http\Controllers\Api\Store\RoleManagementController;
use App\Http\Controllers\Api\Store\StaffController;
use App\Http\Controllers\Api\Store\StoreSettingsController;
use App\Http\Controllers\Api\Store\Catalog\BrandController;
use App\Http\Controllers\Api\Store\Catalog\CategoryController;
use App\Http\Controllers\Api\Store\Catalog\ProductController;
use App\Http\Controllers\Api\Store\Catalog\TaxRateController;
use App\Http\Controllers\Api\Store\Catalog\UnitController;
use App\Http\Controllers\Api\Store\Inventory\GrnController;
use App\Http\Controllers\Api\Store\Inventory\InventoryController;
use App\Http\Controllers\Api\Store\Inventory\PurchaseOrderController;
use App\Http\Controllers\Api\Store\Inventory\StockAdjustmentController;
use App\Http\Controllers\Api\Store\Inventory\StockTransferController;
use App\Http\Controllers\Api\Store\Inventory\SupplierController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Store / Tenant API Routes
| Prefix: /api/v1/store
| Middleware: auth:sanctum, initialize.tenancy, tenant.scope
|--------------------------------------------------------------------------
*/

// Health check
Route::get('test', function () {
    return response()->json([
        'success'  => true,
        'message'  => 'Store routes are working',
        'store_id' => app('current_store_id'),
        'user'     => auth()->user()->only(['id', 'name', 'email']),
    ]);
});

// Billing
Route::prefix('billing')->group(function () {
    Route::get('gateways',               [BillingController::class, 'gateways']);
    Route::get('plans',                  [BillingController::class, 'plans']);
    Route::get('subscription',           [BillingController::class, 'subscription']);
    Route::post('checkout',              [BillingController::class, 'checkout']);
    Route::get('sessions/{sessionId}',   [BillingController::class, 'verifySession']);
    Route::post('cancel',                [BillingController::class, 'cancel']);
    Route::post('change-plan',           [BillingController::class, 'changePlan']);
    Route::get('payments',               [BillingController::class, 'payments']);
    Route::get('payments/{id}/invoice',  [BillingController::class, 'invoice']);
    Route::post('portal',                [BillingController::class, 'portal']);
});

// ============================================================================
// PHASE 4 — CATALOG  (module: products)
// ============================================================================
Route::middleware('module:products')->group(function () {

    // Categories
    Route::prefix('categories')->group(function () {
        Route::get('/',      [CategoryController::class, 'index']);
        Route::post('/',     [CategoryController::class, 'store']);
        Route::put('{id}',   [CategoryController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',[CategoryController::class, 'destroy'])->whereNumber('id');
    });

    // Brands
    Route::prefix('brands')->group(function () {
        Route::get('/',      [BrandController::class, 'index']);
        Route::post('/',     [BrandController::class, 'store']);
        Route::put('{id}',   [BrandController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',[BrandController::class, 'destroy'])->whereNumber('id');
    });

    // Tax rates
    Route::prefix('tax-rates')->group(function () {
        Route::get('/',      [TaxRateController::class, 'index']);
        Route::post('/',     [TaxRateController::class, 'store']);
        Route::put('{id}',   [TaxRateController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',[TaxRateController::class, 'destroy'])->whereNumber('id');
    });

    // Units
    Route::prefix('units')->group(function () {
        Route::get('/',      [UnitController::class, 'index']);
        Route::post('/',     [UnitController::class, 'store']);
        Route::put('{id}',   [UnitController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',[UnitController::class, 'destroy'])->whereNumber('id');
    });

    // Products — lookup must be defined BEFORE /{id} to avoid route conflict
    Route::prefix('products')->group(function () {
        Route::get('lookup', [ProductController::class, 'lookup']);      // barcode/SKU fast lookup
        Route::get('/',      [ProductController::class, 'index']);
        Route::post('/',     [ProductController::class, 'store']);

        Route::prefix('{id}')->whereNumber('id')->group(function () {
            Route::get('/',            [ProductController::class, 'show']);
            Route::put('/',            [ProductController::class, 'update']);
            Route::delete('/',         [ProductController::class, 'destroy']);

            // Variants
            Route::post('variants',                   [ProductController::class, 'storeVariant']);
            Route::put('variants/{variantId}',         [ProductController::class, 'updateVariant'])
                ->whereNumber('variantId');
            Route::delete('variants/{variantId}',      [ProductController::class, 'destroyVariant'])
                ->whereNumber('variantId');

            // Images
            Route::post('images',              [ProductController::class, 'uploadImage']);
            Route::delete('images/{imageId}',  [ProductController::class, 'destroyImage'])
                ->whereNumber('imageId');

            // Barcode generation
            Route::post('barcode/generate',    [ProductController::class, 'generateBarcode']);
        });
    });
});

// ============================================================================
// PHASE 4 — INVENTORY  (module: inventory)
// ============================================================================
Route::middleware('module:inventory')->group(function () {

    // Current stock state + movements
    Route::get('inventory',                    [InventoryController::class, 'index']);
    Route::get('inventory/movements',          [InventoryController::class, 'movements']);
    Route::get('inventory/products/{id}',      [InventoryController::class, 'productStock'])->whereNumber('id');

    // Stock adjustments
    Route::prefix('stock-adjustments')->group(function () {
        Route::get('/',              [StockAdjustmentController::class, 'index']);
        Route::post('/',             [StockAdjustmentController::class, 'store']);
        Route::post('{id}/approve',  [StockAdjustmentController::class, 'approve'])->whereNumber('id');
        Route::post('{id}/reject',   [StockAdjustmentController::class, 'reject'])->whereNumber('id');
    });

    // Stock transfers (sub-module: stock-transfer)
    Route::prefix('stock-transfers')->group(function () {
        Route::get('/',              [StockTransferController::class, 'index']);
        Route::post('/',             [StockTransferController::class, 'store']);
        Route::post('{id}/send',     [StockTransferController::class, 'send'])->whereNumber('id');
        Route::post('{id}/receive',  [StockTransferController::class, 'receive'])->whereNumber('id');
    });
});

// ============================================================================
// PHASE 4 — SUPPLIERS & PURCHASING  (module: suppliers / purchase-orders / grn)
// ============================================================================
Route::middleware('module:suppliers')->group(function () {

    Route::prefix('suppliers')->group(function () {
        Route::get('/',      [SupplierController::class, 'index']);
        Route::get('{id}',   [SupplierController::class, 'show'])->whereNumber('id');
        Route::post('/',     [SupplierController::class, 'store']);
        Route::put('{id}',   [SupplierController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',[SupplierController::class, 'destroy'])->whereNumber('id');
    });

    Route::prefix('purchase-orders')->group(function () {
        Route::get('/',             [PurchaseOrderController::class, 'index']);
        Route::get('{id}',          [PurchaseOrderController::class, 'show'])->whereNumber('id');
        Route::post('/',            [PurchaseOrderController::class, 'store']);
        Route::put('{id}',          [PurchaseOrderController::class, 'update'])->whereNumber('id');
        Route::post('{id}/send',    [PurchaseOrderController::class, 'send'])->whereNumber('id');
        Route::post('{id}/cancel',  [PurchaseOrderController::class, 'cancel'])->whereNumber('id');
        // Create GRN from PO
        Route::post('{poId}/grns',  [GrnController::class, 'storeFromPo'])->whereNumber('poId');
    });

    Route::prefix('grns')->group(function () {
        Route::get('/',      [GrnController::class, 'index']);
        Route::get('{id}',   [GrnController::class, 'show'])->whereNumber('id');
        Route::post('/',     [GrnController::class, 'store']);
    });
});

// ============================================================================
// PHASE 4 — CUSTOMERS  (module: customers)
// ============================================================================
Route::middleware('module:customers')->prefix('customers')->group(function () {
    Route::get('lookup',         [CustomerController::class, 'lookup']);
    Route::get('/',              [CustomerController::class, 'index']);
    Route::post('/',             [CustomerController::class, 'store']);
    Route::get('{id}',           [CustomerController::class, 'show'])->whereNumber('id');
    Route::put('{id}',           [CustomerController::class, 'update'])->whereNumber('id');
    Route::delete('{id}',        [CustomerController::class, 'destroy'])->whereNumber('id');
    Route::get('{id}/purchases', [CustomerController::class, 'purchases'])->whereNumber('id');

    // Phase 4C — notes
    Route::get('{id}/notes',              [CustomerNoteController::class, 'index'])->whereNumber('id');
    Route::post('{id}/notes',             [CustomerNoteController::class, 'store'])->whereNumber('id');
    Route::delete('{id}/notes/{noteId}',  [CustomerNoteController::class, 'destroy'])->whereNumber('id')->whereNumber('noteId');

    // Phase 4C — communications
    Route::get('{id}/communications',  [CommunicationController::class, 'customerHistory'])->whereNumber('id');
    Route::post('{id}/send-message',   [CommunicationController::class, 'sendMessage'])->whereNumber('id');

    // Phase 4C — loyalty history
    Route::get('{id}/loyalty-history', [LoyaltyController::class, 'history'])->whereNumber('id');
    Route::post('{id}/loyalty/adjust', [LoyaltyController::class, 'manualAdjust'])->whereNumber('id');

    // Phase 4C — credit history + payment
    Route::get('{id}/credit-history',  [CreditController::class, 'history'])->whereNumber('id');
    Route::post('{id}/credit/payment', [CreditController::class, 'recordPayment'])->whereNumber('id');
});

// ============================================================================
// PHASE 4C — CUSTOMER GROUPS
Route::middleware('module:customer-groups')->prefix('customer-groups')->group(function () {
    Route::get('/',                         [CustomerGroupController::class, 'index']);
    Route::get('{id}',                      [CustomerGroupController::class, 'show'])->whereNumber('id');
    Route::post('/',                        [CustomerGroupController::class, 'store']);
    Route::put('{id}',                      [CustomerGroupController::class, 'update'])->whereNumber('id');
    Route::delete('{id}',                   [CustomerGroupController::class, 'destroy'])->whereNumber('id');
    Route::get('{id}/customers',            [CustomerGroupController::class, 'customers'])->whereNumber('id');
    Route::post('{id}/bulk-assign',         [CustomerGroupController::class, 'bulkAssign'])->whereNumber('id');
});

// Group pricing on products (module:customer-groups)
Route::middleware('module:customer-groups')->group(function () {
    Route::get('products/{id}/group-prices',          [GroupPricingController::class, 'index'])->whereNumber('id');
    Route::put('products/{id}/group-prices',          [GroupPricingController::class, 'upsert'])->whereNumber('id');
    Route::delete('products/{id}/group-prices/{gid}', [GroupPricingController::class, 'destroy'])->whereNumber('id')->whereNumber('gid');
});

// ============================================================================
// PHASE 4C — LOYALTY
Route::middleware('module:loyalty')->prefix('loyalty')->group(function () {
    Route::get('settings',       [LoyaltyController::class, 'getSettings']);
    Route::put('settings',       [LoyaltyController::class, 'updateSettings']);
    Route::get('stats',          [LoyaltyController::class, 'stats']);
    Route::get('transactions',   [LoyaltyController::class, 'allTransactions']);
});

// ============================================================================
// PHASE 4C — CREDIT
Route::middleware('module:customer-credit')->prefix('credit')->group(function () {
    Route::get('outstanding', [CreditController::class, 'outstanding']);
    Route::get('aging',       [CreditController::class, 'aging']);
    Route::get('payments',    [CreditController::class, 'payments']);
});

// ============================================================================
// PHASE 4C — COMMUNICATIONS + SEGMENTS
Route::middleware('module:customer-communications')->group(function () {
    Route::get('communications',     [CommunicationController::class, 'index']);
    // NOTE: GET message-templates removed — superseded by Phase 5 MessageTemplateController CRUD group below
});

Route::middleware('module:customers')->prefix('customer-segments')->group(function () {
    Route::get('/',           [CustomerSegmentController::class, 'index']);
    Route::post('/',          [CustomerSegmentController::class, 'store']);
    Route::put('{id}',        [CustomerSegmentController::class, 'update'])->whereNumber('id');
    Route::delete('{id}',     [CustomerSegmentController::class, 'destroy'])->whereNumber('id');
    Route::post('{id}/preview',[CustomerSegmentController::class, 'preview'])->whereNumber('id');
});

// ============================================================================
// PHASE 4 — POS SALES  (module: pos-sales)
// ============================================================================
Route::middleware('module:pos-sales')->group(function () {

    // ── Cart (draft sale) operations ─────────────────────────────────────────
    Route::prefix('pos')->group(function () {
        Route::get('sales',                    [PosController::class, 'createSale']);    // resume/check
        Route::post('sales',                   [PosController::class, 'createSale']);
        Route::post('sales/{id}/items',        [PosController::class, 'addItem'])->whereNumber('id');
        Route::put('sales/{id}/items/{itemId}',[PosController::class, 'updateItem'])->whereNumber('id')->whereNumber('itemId');
        Route::delete('sales/{id}/items/{itemId}', [PosController::class, 'removeItem'])->whereNumber('id')->whereNumber('itemId');
        Route::post('sales/{id}/discount',     [PosController::class, 'applyDiscount'])->whereNumber('id');
        Route::post('sales/{id}/customer',     [PosController::class, 'attachCustomer'])->whereNumber('id');
        Route::post('sales/{id}/payments',     [PosController::class, 'addPayment'])->whereNumber('id');
        Route::post('sales/{id}/complete',     [PosController::class, 'completeSale'])->whereNumber('id');
        Route::post('sales/{id}/void',         [PosController::class, 'voidSale'])->whereNumber('id');
        Route::get('sales/{id}/receipt',       [PosController::class, 'receipt'])->whereNumber('id');

        // Hold (parked) sales
        Route::post('hold',            [PosController::class, 'holdSale']);
        Route::get('hold',             [PosController::class, 'listHeld']);
        Route::post('hold/{id}/resume',[PosController::class, 'resumeHeld'])->whereNumber('id');
        Route::delete('hold/{id}',     [PosController::class, 'deleteHeld'])->whereNumber('id');

        // ── Offline sync — SERVER → CLIENT data preload (Phase 6) ───────────────
        Route::prefix('sync')->group(function () {
            Route::get('manifest',  [PosSyncController::class, 'manifest']);
            Route::get('products',  [PosSyncController::class, 'products']);
            Route::get('customers', [PosSyncController::class, 'customers']);
            Route::get('reference', [PosSyncController::class, 'reference']);
            // CLIENT → SERVER: upload offline-created sales
            Route::post('sales',    [OfflineSalesSyncController::class, 'syncSales']);
        });

        // ── Sync conflict management (Phase 6) ───────────────────────────────────
        Route::prefix('sync-conflicts')->group(function () {
            Route::get('/',                [OfflineSalesSyncController::class, 'conflicts']);
            Route::post('{id}/resolve',    [OfflineSalesSyncController::class, 'resolveConflict'])->whereNumber('id');
        });

        // ── Device registry (Phase 6 — PWA offline) ──────────────────────────────
        Route::post('devices/register',          [DeviceController::class, 'register']);
        Route::post('devices/{id}/ping',         [DeviceController::class, 'ping'])->whereNumber('id');
        Route::middleware('permission:manage-pos-devices')->group(function () {
            Route::get('devices',                [DeviceController::class, 'index']);
            Route::put('devices/{id}',           [DeviceController::class, 'update'])->whereNumber('id');
            Route::delete('devices/{id}',        [DeviceController::class, 'destroy'])->whereNumber('id');
            Route::get('devices/{id}/sync-status',[DeviceController::class, 'syncStatus'])->whereNumber('id');
        });

        // Cash drawer
        Route::post('drawer/open',     [PosController::class, 'openDrawer']);
        Route::get('drawer/current',   [PosController::class, 'currentDrawer']);
        Route::post('drawer/close',    [PosController::class, 'closeDrawer']);
        Route::get('drawer/history',   [PosController::class, 'drawerHistory']);
    });

    // ── Sale history ──────────────────────────────────────────────────────────
    Route::prefix('sales')->group(function () {
        Route::get('/',      [SaleController::class, 'index']);
        Route::get('{id}',   [SaleController::class, 'show'])->whereNumber('id');
    });

    // ── Returns (permission: refund-sales) ────────────────────────────────────
    Route::post('sales/{id}/returns',  [SaleController::class, 'createReturn'])->whereNumber('id');
    Route::get('returns',              [SaleController::class, 'listReturns']);
    Route::get('returns/{id}',         [SaleController::class, 'showReturn'])->whereNumber('id');
});

// ============================================================================
// File serving — tenant-aware, auth required
// ============================================================================
Route::get('files/{path}', function (Request $request, string $path) {
    if (! $request->user()) {
        abort(401);
    }
    if (! Storage::disk('local')->exists($path)) {
        abort(404);
    }
    return Storage::disk('local')->response($path);
})->where('path', '.*');

// ============================================================================
// PHASE 4 — RECEIPT TEMPLATES & STORE SETTINGS
// ============================================================================

// Receipt templates
Route::prefix('receipt-templates')->group(function () {
    Route::get('/',                    [ReceiptTemplateController::class, 'index']);
    Route::post('/',                   [ReceiptTemplateController::class, 'store']);
    Route::get('{id}',                 [ReceiptTemplateController::class, 'show'])->whereNumber('id');
    Route::put('{id}',                 [ReceiptTemplateController::class, 'update'])->whereNumber('id');
    Route::delete('{id}',              [ReceiptTemplateController::class, 'destroy'])->whereNumber('id');
    Route::get('{id}/preview',         [ReceiptTemplateController::class, 'preview'])->whereNumber('id');
});

// Store profile (name, currency, timezone, contact)
Route::get('profile',  [StoreSettingsController::class, 'getProfile']);
Route::put('profile',  [StoreSettingsController::class, 'updateProfile']);

// POS / store settings (key-value store in tenant DB)
Route::prefix('settings')->group(function () {
    Route::get('/',    [StoreSettingsController::class, 'index']);
    Route::put('/',    [StoreSettingsController::class, 'update']);
    Route::post('logo',      [StoreSettingsController::class, 'uploadLogo']);
    Route::put('whatsapp',   [StoreSettingsController::class, 'updateWhatsapp']);
});

// ── Role management (requires manage-users) ──────────────────────────────────
Route::middleware('module:staff')->prefix('roles')->group(function () {
    Route::get('/',                        [RoleManagementController::class, 'index']);
    Route::post('/',                       [RoleManagementController::class, 'store']);
    Route::get('permissions',              [RoleManagementController::class, 'permissions']);
    Route::get('{id}',                     [RoleManagementController::class, 'show'])->whereNumber('id');
    Route::put('{id}',                     [RoleManagementController::class, 'update'])->whereNumber('id');
    Route::delete('{id}',                  [RoleManagementController::class, 'destroy'])->whereNumber('id');
    Route::put('{id}/permissions',         [RoleManagementController::class, 'syncPermissions'])->whereNumber('id');
});

// ── Staff management (requires manage-users) ────────────────────────────────
Route::middleware('module:staff')->prefix('staff')->group(function () {
    Route::get('/',                        [StaffController::class, 'index']);
    Route::post('/',                       [StaffController::class, 'store']);
    Route::put('{id}',                     [StaffController::class, 'update'])->whereNumber('id');
    Route::delete('{id}',                  [StaffController::class, 'destroy'])->whereNumber('id');
});

// Branches — simple list used by inventory, POS, etc.
Route::get('branches', function () {
    $branches = \App\Models\Branch::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'is_main']);
    return response()->json(['success' => true, 'data' => $branches]);
});

// ============================================================================
// PHASE 4D — EXPENSES  (permission: manage-expenses)
// ============================================================================
Route::prefix('expenses')->group(function () {
    Route::get('categories',  [ExpenseController::class, 'categories']);
    Route::get('/',           [ExpenseController::class, 'index']);
    Route::post('/',          [ExpenseController::class, 'store']);
    Route::put('{id}',        [ExpenseController::class, 'update'])->whereNumber('id');
    Route::delete('{id}',     [ExpenseController::class, 'destroy'])->whereNumber('id');
});

// ============================================================================
// PHASE 4D — REPORTS  (module: reports, permission: view-reports)
// ============================================================================
Route::middleware('module:reports')->prefix('reports')->group(function () {
    Route::get('/',                     [ReportController::class, 'index']);
    Route::get('{slug}/schema',         [ReportController::class, 'schema']);
    Route::post('{slug}/run',           [ReportController::class, 'run']);
    Route::post('{slug}/export',        [ReportController::class, 'export']);

    // Scheduled reports
    Route::prefix('scheduled')->group(function () {
        Route::get('/',               [ScheduledReportController::class, 'index']);
        Route::post('/',              [ScheduledReportController::class, 'store']);
        Route::put('{id}',            [ScheduledReportController::class, 'update'])->whereNumber('id');
        Route::delete('{id}',         [ScheduledReportController::class, 'destroy'])->whereNumber('id');
        Route::post('{id}/send-now',  [ScheduledReportController::class, 'sendNow'])->whereNumber('id');
    });
});

// ============================================================================
// PHASE 5 — COMMUNICATION SETTINGS (module: customer-communications)
// ============================================================================
Route::middleware('module:customer-communications')->prefix('communication-settings')->group(function () {
    Route::get('settings',            [CommunicationSettingsController::class, 'getSettings']);
    Route::put('settings',            [CommunicationSettingsController::class, 'updateSettings']);
    Route::get('usage',               [CommunicationSettingsController::class, 'getUsage']);
    Route::get('opt-outs',            [CommunicationSettingsController::class, 'getOptOuts']);
    Route::delete('opt-outs/{id}',    [CommunicationSettingsController::class, 'deleteOptOut'])->whereNumber('id');
});

Route::middleware('module:customer-communications')->prefix('campaigns')->group(function () {
    Route::get('/',                     [CampaignController::class, 'index']);
    Route::post('/',                    [CampaignController::class, 'store']);
    Route::post('estimate-audience',    [CampaignController::class, 'estimateAudience']);
    Route::get('{id}',                  [CampaignController::class, 'show'])->whereNumber('id');
    Route::put('{id}',                  [CampaignController::class, 'update'])->whereNumber('id');
    Route::delete('{id}',               [CampaignController::class, 'destroy'])->whereNumber('id');
    Route::post('{id}/launch',          [CampaignController::class, 'launch'])->whereNumber('id');
    Route::post('{id}/cancel',          [CampaignController::class, 'cancel'])->whereNumber('id');
    Route::get('{id}/stats',            [CampaignController::class, 'stats'])->whereNumber('id');
});

Route::middleware('module:customer-communications')->prefix('message-templates')->group(function () {
    Route::get('/',                  [MessageTemplateController::class, 'index']);
    Route::post('/',                 [MessageTemplateController::class, 'store']);
    Route::get('{id}',               [MessageTemplateController::class, 'show'])->whereNumber('id');
    Route::put('{id}',               [MessageTemplateController::class, 'update'])->whereNumber('id');
    Route::delete('{id}',            [MessageTemplateController::class, 'destroy'])->whereNumber('id');
    Route::post('{id}/duplicate',    [MessageTemplateController::class, 'duplicate'])->whereNumber('id');
});

// ============================================================================
// PHASE 8.1 — DATA EXPORT  (permission: manage-settings)
// ============================================================================
Route::prefix('data-export')->group(function () {
    Route::get('/',                     [DataExportController::class, 'export']);
    Route::get('{exportId}/{file}',     [DataExportController::class, 'download']);
});

// ============================================================================
// PHASE 8.1 — ACCOUNT MANAGEMENT  (role: store-owner)
// ============================================================================
Route::prefix('account')->group(function () {
    Route::post('request-deletion',     [AccountController::class, 'requestDeletion']);
});

// ============================================================================
// Module ping routes (for client-side capability checks)
// ============================================================================
Route::middleware('module:pos-sales')->get('pos/ping', fn () => response()->json(['success' => true, 'module' => 'pos-sales']));
Route::middleware('module:inventory')->get('inventory/ping', fn () => response()->json(['success' => true, 'module' => 'inventory']));
Route::middleware('module:reports')->get('reports/ping', fn () => response()->json(['success' => true, 'module' => 'reports']));
