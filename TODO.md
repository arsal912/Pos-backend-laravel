# Phase 4A + 4B + 4C + 4D — Known Rough Edges & Follow-up Tasks

Last updated: 2026-07-04

---

## 🔴 Blocking / Must-fix before Production

### B1. Branch ID hardcoded to 1
**Where:** PosController, StockAdjustmentController, cash drawer endpoints, frontend pages  
**Issue:** All operations default to `branch_id = 1`. Multi-branch stores will have incorrect data.  
**Fix:** Add a `GET /store/branches` endpoint. Frontend branch selector should load from API and persist selection in localStorage per user session.

### B2. Sale number race condition
**Where:** `app/Models/Sale.php::generateNumber()`  
**Issue:** Uses `COUNT(*) + 1` which is not atomic. Two concurrent sales could get the same number.  
**Fix:** Use a DB sequence or an `invoice_counters`-style atomic counter table (same pattern as billing invoice numbers).

### B3. Queue worker required for aggregate sync
**Where:** `SyncStoreAggregate` job, `SaleObserver`  
**Issue:** Without a queue worker, `SyncStoreAggregate` jobs pile up silently. Admin dashboard stays stale.  
**Fix (development):** Set `QUEUE_CONNECTION=sync` in `.env` for immediate synchronous processing.  
**Fix (production):** Run `php artisan queue:work --daemon` as a supervised process.

### B4. Module access for Phase 4 routes
**Where:** All Phase 4 store routes use `module:products`, `module:inventory`, `module:customers`, `module:pos-sales`  
**Issue:** New stores don't have these modules enabled by default unless the plan includes them.  
**Fix:** Go to Admin → Modules → select the store → enable all Phase 4 modules. OR update the `PlanSeeder` to include these modules in default plans.

### B5. ~~`user_modules` table missing from the central DB~~ — FIXED 2026-07-05
**Where:** `App\Models\User::userModules()` / `hasModuleAccess()` — queries the central `mysql` connection (User's fixed `$connection`)  
**Issue:** `store_modules` AND `user_modules` were both created by `database/migrations/tenant/2024_01_01_000005_create_store_and_user_modules_tables.php`, which only runs against per-store tenant DBs — but `Module`, `StoreModule`, and `UserModule` are all hardcoded to `$connection = 'mysql'` (central). Neither table existed centrally, so any store user hitting a `module:*`-gated route got `SQLSTATE[42S02]: Base table or view not found`. Confirmed via `/store/products`, `/store/reports/{slug}/run`.  
**Fix applied:** Moved the migration from `database/migrations/tenant/` to `database/migrations/` (central) and ran it — the file was simply filed in the wrong directory; the two models it backs never belonged in a tenant DB. Also found and fixed the same unguarded-`cache()->remember()`-under-tenancy bug (see D1) in `Store::hasModuleAccess()` and `CommunicationsManager::resolve()`, which the fix exposed by letting requests reach further down the same fallback cascade.
**Second finding surfaced by this fix, also FIXED 2026-07-05:** `GET /store/products` then failed with `Table 'pos_store_2.permissions' doesn't exist (Connection: tenant, ...)`. Root cause was NOT a tenancy/connection bug — `config('permission.models.permission')` was resolving to the base `Spatie\Permission\Models\Permission` instead of `App\Models\Permission` (which hardcodes `$connection = 'mysql'`). `bootstrap/cache/config.php` had been generated on 2026-07-03 19:15, and `config/permission.php`'s custom central-model bindings were added the next day (2026-07-04 16:05) — so the cached config silently shadowed the fix ever since it was written, and every `config()` call kept returning pre-edit values. Fixed by running `php artisan config:clear`. **Takeaway:** if you edit any `config/*.php` file in this environment and `bootstrap/cache/config.php` exists, your edit is silently ignored until you `config:clear` — check `ls -la bootstrap/cache/config.php` vs. the config file's mtime if a setting "isn't taking effect."

---

## 🟡 Important — Fix Soon

### I1. Variable product variant picker in POS
**Where:** `app/dashboard/pos/page.tsx` — product grid click handler  
**Issue:** Clicking a variable product adds the first variant (or no variant) without asking which variant to use.  
**Fix:** On click of a variable product, open a variant picker modal before calling `addProductToCart()`. Backend `products/lookup` already returns variant list for variable products.

### I2. ~~Receipt templates not wired to receipt generation~~ — FIXED 2026-07-05
**Where:** `app/Http/Controllers/Api/Store/Pos/PosController::receipt()`  
**Fix applied:** `receipt()` now looks up `ReceiptTemplate::where('type', $format)->where('is_active', true)->where('is_default', true)->first()` and passes it to `pos.receipt-thermal`/`pos.receipt-a4`, which now apply `header_text`/`footer_text` (with `{{store.name}}`-style merge tags via the new `receipt_merge_tags()` helper in `app/helpers.php`), `show_logo` (rendered as a base64 data URI via `receipt_logo_data_uri()` — the store logo lives on the private `local` disk, so a data URI is what actually renders in both a print window and a dompdf PDF without needing a public/authenticated URL), `show_tax_breakdown`, and `custom_css`. Same wiring applied to `ReceiptTemplateController::preview()` / `receipt-preview.blade.php` so the settings-page preview matches real output.
**Also added (new, was not part of this TODO item):** a platform-wide receipt footer, set only by the super admin at `PUT /admin/settings/receipt-footer` (`App\Models\PlatformReceiptSetting`, central DB singleton, same pattern as `LandingPageSetting`), unconditionally appended below the store's own footer on every receipt/PDF/preview. Store owners see it in their preview (labeled "not editable here") but have no route to change it.

### I3. POS draft sale not resumed on page load
**Where:** `app/dashboard/pos/page.tsx` — `useEffect` init  
**Issue:** On every page load, a new draft sale is created. If the cashier refreshes the browser mid-sale, the draft items are lost (though they exist in the DB).  
**Fix:** On init, call `GET /pos/sales?status=draft&cashier_id=me` to find any open draft for this user, and restore it if found. The localStorage `CART_KEY` provides the ID, but the API call is needed to restore items.

### I4. Hold sale resume doesn't restore cart
**Where:** `PosController::resumeHeld()` + POS frontend  
**Issue:** The hold resume endpoint returns the hold data JSON but the frontend doesn't rebuild the cart from it.  
**Fix:** On resume, parse `hold.data.items` and call `POST /pos/sales/{id}/items` for each item to rebuild the draft sale, then delete the hold.

### I5. ~~Barcode print shows text labels, not scannable images~~ — FIXED 2026-07-05
**Where:** `app/dashboard/products/[id]/print-barcode/page.tsx`  
**Fix applied:** New `POST /store/products/barcode-labels` (`App\Http\Controllers\Api\Store\Catalog\BarcodeLabelController`) takes `{product_ids: []}` and renders a real SVG per product via `milon/barcode` (`DNS1D::getBarcodeSVG`). If a product already has a `barcode`, it's used as-is (EAN13 if it's a valid 12-13 digit code, else CODE128). If not, a real EAN-13 is generated via `App\Services\BarcodeService::generateUniqueEan13()` (shared with the pre-existing single-product `generateBarcode` endpoint — same algorithm, one implementation) and **persisted to the product** before rendering, so it's not just a print-time stand-in. Generating a new barcode requires `edit-products`; printing an existing one only needs `view-products` (checked conditionally, so view-only users printing already-barcoded products aren't blocked). Shared `components/catalog/BarcodeLabel.tsx` renders one label (real image + name + SKU + price) and is used by both this page and the new bulk **Barcode Generator** module (`/dashboard/products/barcode-generator` — select multiple products, per-product quantity, print sheet; shows a "will create" badge for any selection without a barcode yet). PDF export was considered but skipped — the existing `window.print()` client-print pattern this page already used works fine for real images too (SVG prints natively), so no dompdf round-trip was needed. Went with SVG over PNG since it's crisp at any print DPI and simpler to inline in HTML.
**Print layout fix (2026-07-05, same day):** actual print output (verified via `Page.printToPDF`, not a screenshot — screenshots don't respect `@media print`) was broken on both pages: the single-product page printed a **blank page** (dead pre-existing CSS `body > *:not(.print-area) { display: none; }` with no element ever having that class — deleted), and the bulk page printed the **entire dashboard sidebar** alongside the labels (the sidebar is rendered by `app/dashboard/layout.tsx`, so no `print:hidden` inside either page could ever reach it — fixed by adding `print:hidden` to the `<aside>` and `print:p-0 print:max-w-none` to the main content wrapper in the layout itself, so this fix covers every page under `/dashboard`, not just these two). Also dropped the print grid from 5 to 3 columns (5 made barcodes small/hard-to-scan) and stopped truncating product names in print.

---

## 🟢 Nice-to-Have / Phase 4C Items

### N1. QZ Tray thermal printer integration
The spec mentioned QZ Tray for direct ESC/POS thermal printing and cash drawer control. Not implemented. The current receipt printing uses browser `window.print()` which works for A4 but is suboptimal for 80mm thermal.

**Partial workaround:** The receipt endpoint returns 80mm-formatted HTML. Opening it in a dedicated tab and printing with "Paper size: Custom 80mm" works.

### N2. WhatsApp receipt sharing
The spec mentioned constructing a `wa.me` link. Easy to add:
```typescript
const whatsappUrl = `https://wa.me/${customer.phone}?text=${encodeURIComponent(`Receipt: ${sale.sale_number}, Total: ${sale.total}`)}`;
window.open(whatsappUrl, '_blank');
```

### N3. Email receipt from POS screen
After completing a sale, the receipt screen has a placeholder "Email Receipt" button. Needs a backend endpoint:
```
POST /pos/sales/{id}/email-receipt
```
That sends `PaymentReceipt` mail with PDF attached.

### N4. F9 keyboard shortcut (quick exact-cash payment)
The spec defined F9 = quick cash payment with exact total. The keyboard handler exists but doesn't auto-fill and complete. Add: open payment modal with amount pre-filled to the total.

### N5. Loyalty points in customer modal
The customer card in POS shows no loyalty/points. Phase 4C is the "deeper CRM" phase where this would live.

### N6. Product image in receipts
Images are served through `/api/backend/store/files/{path}` which requires Bearer auth. Email receipts won't show images. Fix: make images public (use the `public` storage disk) or embed as base64 in PDF receipts.

### N7. Multi-branch module gate for stock transfers
`/dashboard/stock-transfers` is always shown in nav but the spec says it should only appear if the `multi-branch` module is enabled. Gate the nav item and the page behind a module check.

### N8. Sales reports page
`/dashboard/reports` is in the nav but has no implementation. Phase 4C should add:
- Daily/weekly/monthly sales summary
- Top products report
- Cashier performance report
- Stock valuation report

### N9. Pagination for inventory adjustment history
`GET /store/stock-adjustments` returns paginated results but the frontend adjustment page doesn't show history — only the create form. Add a history table below the form.

### N10. PayPal plan sync (`paypal:sync-plans`) needs testing
The command exists and creates PayPal Products + Billing Plans, but it hasn't been tested end-to-end with a sandbox account. The PayPal Subscriptions API requires specific settings (e.g., `AUTO_BILL_OUTSTANDING=true`) that may need tweaking based on actual sandbox behavior.

---

## 📋 Production Checklist

Before going live, complete these:

```
[ ] Set QUEUE_CONNECTION=redis (or database) and run queue:work as daemon
[ ] Set up cron: * * * * * cd /path/to/pos-backend && php artisan schedule:run >> /dev/null 2>&1
[ ] Configure STRIPE_KEY, STRIPE_SECRET, STRIPE_WEBHOOK_SECRET in .env
[ ] Run: php artisan stripe:sync-plans
[ ] Enable Phase 4 modules for all active stores
[ ] Set APP_DEBUG=false in production
[ ] Configure APP_URL and FRONTEND_URL correctly
[ ] Set up Redis for session/cache
[ ] Test QZ Tray with actual thermal printer
[ ] Run: php artisan store-aggregates:sync (initial aggregate population)
[ ] Verify ngrok or public URL for JazzCash/Easypaisa return URLs
[ ] Test SMS/email notifications with real SMTP
[ ] Set up S3 or object storage for file/image uploads
[ ] Configure SANCTUM_STATEFUL_DOMAINS if using cookie auth
[ ] Run php artisan db:seed --class=PaymentGatewaySeeder on production
```

---

## 🏗️ Architecture Decisions Made in Phase 4

| Decision | Reason |
|---|---|
| Branch ID defaults to 1 | Simplifies Phase 4A; full multi-branch in Phase 4C |
| `meta` JSON for aggregate Phase 4 data | Avoids new central DB migration; flexible for future fields |
| FULLTEXT index on products(name,sku,barcode) | Fast POS search without Elasticsearch |
| Barcode scanner: < 100ms input = scan | Standard threshold; override per-store in settings |
| `sometimes\|boolean` replaced with `filter_var` | Proxy encoding can mangle JSON booleans |
| Tenant models: no `$connection` property | stancl/tenancy handles switching at runtime |
| Receipt templates stored in tenant DB | Store-specific; makes import/export complete |
| Sale items denormalize product_name + sku | Receipt history survives product deletion |
| StockService as single entry point | Prevents direct inventory_items manipulation; ensures audit trail |

---

## 🔴 Phase 4D — Known Rough Edges

### D1. Report cache disabled in tenant context
**Where:** `app/Reports/BaseReport::remember()`
**Issue:** stancl's `CacheTenancyBootstrapper` applies tagged cache. The default file driver doesn't support tags, causing a `BadMethodCallException`. The `remember()` method uses the `array` driver as a fallback (in-process cache only — doesn't persist between requests).
**Fix for production:** Change `CACHE_STORE=redis` in `.env` and uncomment `RedisTenancyBootstrapper` in `config/tenancy.php`. Redis supports tagged cache natively. Alternatively, configure a separate `database` cache driver for reports.
**Update (2026-07-04):** the same `BadMethodCallException` was also hitting `App\Models\User::hasModuleAccess()`, which had a bare `cache()->remember()` with no guard — this broke every `module:*`-gated store route with `CACHE_STORE=database`, not just report caching. Fixed by wrapping it in the same try/catch-fallback pattern used here (falls back to an uncached lookup instead of a 500). If you add new `cache()->remember()` calls anywhere reachable from an authenticated store request, they need the same guard until `CACHE_STORE=redis` is set.

### D2. Report category filter for `sales-by-product` and `sales-by-category` show empty options
**Where:** Filter schema — `options: []` for category/brand dropdowns
**Issue:** The `/schema` endpoint returns empty `options` arrays for dynamic selects (categories, brands, plans). These would need a separate API call to populate.
**Fix:** Either: (a) add a `options_source` field to filter schema that the frontend resolves via a known endpoint, or (b) pre-populate options in `getFilterSchema()` by querying the tenant DB at schema-load time.

### D3. Admin reports use store_aggregates which are not real-time
**Where:** `app/Reports/Admin/PlatformRevenueReport.php`, `StoresHealthReport.php`
**Issue:** `store_aggregates.today_revenue` and `month_revenue` are populated by the daily `store-aggregates:sync` command. If the queue is not running, these are stale.
**Fix:** Ensure `php artisan queue:work` runs in production. For real-time admin reporting, the analytics controller (`/admin/stores/{id}/analytics`) queries tenant DBs on-demand.

### D4. DemoDataSeeder creates non-idempotent sales
**Where:** `database/seeders/DemoDataSeeder.php`
**Issue:** Running `php artisan tenant:seed-demo {id}` twice creates ~500 more sales each time. It does NOT check for existing sales.
**Fix (acceptable for demo):** This is intentional — more sales = more realistic test data. But if you need idempotency, add: `if (DB::table('sales')->count() > 100) { $this->command->warn('Sales already seeded.'); return; }`

### D5. P&L operating expenses depend on expenses table having data
**Where:** `app/Reports/Financial/ProfitLossReport.php`
**Issue:** If no expenses have been entered, the P&L shows `GROSS PROFIT = NET PROFIT` (no operating expense section). This is mathematically correct but looks incomplete.
**Fix:** DemoDataSeeder now seeds 15 expenses. Encourage merchants to log expenses at `/dashboard/expenses` or via `POST /store/expenses`.

### D6. Scheduled reports send NOW logs to communication_logs as status='skipped'
**Where:** `app/Http/Controllers/Api/Store/ScheduledReportController::sendNow()`
**Issue:** Phase 4D logs reports but doesn't send real emails (Phase 5 will add providers).
**Fix (Phase 5):** Replace the `CommunicationLog` creation with a `Mail::to($email)->send(new ScheduledReportMail($result, $schedule))` call once a mail driver with attachment support is configured.

---

## 📋 Production Checklist (Additions for Phase 4D)

```
[ ] Set CACHE_STORE=redis for report caching to work correctly in tenant context
[ ] Enable reports module for stores in Admin → Modules
[ ] Run php artisan tenant:seed-demo {id} on test stores before demos
[ ] Set up scheduled reports via /dashboard/reports/{slug} → Schedule button
[ ] Enable reports:dispatch-scheduled in cron (already scheduled every 15 min)
[ ] For FBR compliance: integrate with FBR IRIS API separately (see tax report notes)
```
