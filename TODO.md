# Phase 4A + 4B — Known Rough Edges & Follow-up Tasks

Last updated: 2026-06-01

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

---

## 🟡 Important — Fix Soon

### I1. Variable product variant picker in POS
**Where:** `app/dashboard/pos/page.tsx` — product grid click handler  
**Issue:** Clicking a variable product adds the first variant (or no variant) without asking which variant to use.  
**Fix:** On click of a variable product, open a variant picker modal before calling `addProductToCart()`. Backend `products/lookup` already returns variant list for variable products.

### I2. Receipt templates not wired to receipt generation
**Where:** `app/Http/Controllers/Api/Store/Pos/PosController::receipt()`  
**Issue:** The receipt always uses the static Blade templates (`pos.receipt-thermal`, `pos.receipt-a4`). The custom templates created in Settings → Receipt Templates are stored but never used.  
**Fix:** In `receipt()`, look up `ReceiptTemplate::where('is_default', true)->where('type', $format)->first()` and apply its settings (header_text, footer_text, show_logo, show_tax_breakdown) to the Blade template.

### I3. POS draft sale not resumed on page load
**Where:** `app/dashboard/pos/page.tsx` — `useEffect` init  
**Issue:** On every page load, a new draft sale is created. If the cashier refreshes the browser mid-sale, the draft items are lost (though they exist in the DB).  
**Fix:** On init, call `GET /pos/sales?status=draft&cashier_id=me` to find any open draft for this user, and restore it if found. The localStorage `CART_KEY` provides the ID, but the API call is needed to restore items.

### I4. Hold sale resume doesn't restore cart
**Where:** `PosController::resumeHeld()` + POS frontend  
**Issue:** The hold resume endpoint returns the hold data JSON but the frontend doesn't rebuild the cart from it.  
**Fix:** On resume, parse `hold.data.items` and call `POST /pos/sales/{id}/items` for each item to rebuild the draft sale, then delete the hold.

### I5. Barcode print shows text labels, not scannable images
**Where:** `app/dashboard/products/[id]/print-barcode/page.tsx`  
**Issue:** The print page shows text-only labels. No actual barcode image is generated.  
**Fix:** Add backend endpoint `POST /products/{id}/barcode/print?qty=N` that uses `milon/barcode` to generate SVG/PNG barcodes and returns a PDF via laravel-dompdf. The `milon/barcode` package is already in `composer.json`.

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
