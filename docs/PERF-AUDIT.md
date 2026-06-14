# Performance Audit Report — Phase 7 Steps 3-4

## Executive Summary

The POS platform has four confirmed N+1 query patterns and two critical unoptimised dashboard endpoints that execute 18+ sequential database queries on every page load with no caching, making backend performance the most urgent concern. On the frontend, the absence of any dynamic imports causes the entire JS bundle — including a ~230 KB recharts dependency — to load on every page visit regardless of whether chart components are ever rendered. Three database indexes required by the Phase 7 spec are missing and must be created before production deployment.

---

## Step 3: Backend Performance Findings

### N+1 Query Risks

| # | File | Issue | Recommended Fix |
|---|------|-------|-----------------|
| 1 | `app/Http/Controllers/Api/Store/Catalog/ProductController.php` — `index()` lines 78-81 | After paginating products, `transform()` calls `$p->totalStock()` on every row, executing one `SELECT SUM(quantity)` per product. 20 products per page = 21 queries; 100 per page = 101 queries. | Collect all paginated product IDs, run a single `InventoryItem::whereIn('product_id', $ids)->groupBy('product_id')->selectRaw('product_id, SUM(quantity) as qty')->pluck('qty','product_id')`, then map results into the transform closure by key. |
| 2 | `app/Http/Middleware/ModuleAccess.php` + `User::hasModuleAccess()` + `Store::hasModuleAccess()` | Every route guarded by `module:slug` fires 2-3 chained DB queries (two `whereHas` subqueries and a potential `activeSubscription->plan->hasModule()` chain) on every single request, with no caching. | Cache the access decision: `Cache::remember("module_access:{$user->id}:{$moduleSlug}", 300, fn() => ...)`. Invalidate via Model Observer on `StoreModule` / `UserModule` create/update/delete events. |
| 3 | `app/Http/Controllers/Api/Store/Pos/PosController.php` — `completeSale()` line 332 | A bare `Customer::find($sale->customer_id)` executes outside the eager-load chain whenever a credit payment exists. On line 393, `$sale->fresh(...)` issues a full model reload. The `items.variant` relationship is never eager-loaded despite the receipt view potentially needing it. | Add `'customer'` and `'items.variant'` to the initial `with()` call on line 310. Replace the bare `Customer::find()` with `$sale->customer`. Audit whether `fresh()` on line 393 is necessary given the in-transaction state is already correct. |
| 4 | `app/Http/Controllers/Api/Store/CustomerController.php` — `index()` lines 21-31 | `Customer::query()` has no eager loading. If the API resource serialises `customer_group`, every customer row triggers a lazy-load, producing N queries for N customers in the paginated result. | Add `->with('group:id,name,slug,default_discount_percent')` to the query. Confirm no `$appends` or API resource auto-accesses the relationship without the eager load. |

**Additional sub-issue — Stripe retry command (`routes/console.php` lines 369-373):**
The `subscriptions:retry-failed` command filters a PHP collection using `Payment::where(...)->count() < 3`, executing one `SELECT COUNT(*)` per Stripe subscription object. With 100 pending subscriptions this is 100 extra queries. Fix: move the count check to the query builder using `->withCount(['payments as failed_payments_count' => fn($q) => $q->where('status','failed')])->having('failed_payments_count', '<', 3)` before calling `->get()`.

---

### Missing Database Indexes

The following three indexes are required by the Phase 7 spec but are absent from the `pos_store_10` tenant database. All other spec-required indexes are present.

| Index | Table | Columns | Status |
|-------|-------|---------|--------|
| `sales_customer_id_status_index` | `sales` | `(customer_id, status)` | **Missing — must be added** |
| `stock_movements_branch_id_type_index` | `stock_movements` | `(branch_id, type)` | **Missing — must be added** |
| `communication_logs_customer_id_channel_created_at_index` | `communication_logs` | `(customer_id, channel, created_at)` | **Missing — must be added** |
| `sales_cashier_id_sale_date_index` | `sales` | `(cashier_id, sale_date)` | Already exists |
| `sales_branch_id_sale_date_status_index` | `sales` | `(branch_id, sale_date, status)` | Already exists |
| `stock_movements_product_id_variant_id_created_at_index` | `stock_movements` | `(product_id, variant_id, created_at)` | Already exists |
| `loyalty_transactions_customer_id_created_at_index` | `loyalty_transactions` | `(customer_id, created_at)` | Already exists |
| api_loggings indexes (5 total) | `api_loggings` (central DB) | Various | Already exist |

**Migration stubs for the three missing indexes:**

```php
// sales: add (customer_id, status)
Schema::table('sales', function (Blueprint $table) {
    $table->index(['customer_id', 'status'], 'sales_customer_id_status_index');
});

// stock_movements: add (branch_id, type)
Schema::table('stock_movements', function (Blueprint $table) {
    $table->index(['branch_id', 'type'], 'stock_movements_branch_id_type_index');
});

// communication_logs: add (customer_id, channel, created_at)
Schema::table('communication_logs', function (Blueprint $table) {
    $table->index(
        ['customer_id', 'channel', 'created_at'],
        'communication_logs_customer_id_channel_created_at_index'
    );
});
```

---

### Cache Opportunities

| Endpoint / Layer | Recommended Cache Key | TTL | Invalidation Strategy |
|------------------|-----------------------|-----|-----------------------|
| Admin dashboard stats — `DashboardController::index()` | `admin:dashboard:stats` | 60 s | Time-based only |
| Module access decision — `ModuleAccess` middleware | `module_access:{userId}:{moduleSlug}` | 300 s | Model Observer on `StoreModule` / `UserModule` create/update/delete |
| POS reference data — `PosSyncController::reference()` | `pos_reference:{storeId}` | 600 s | Observer on Category, Brand, Unit, TaxRate, CustomerGroup mutation |
| POS sync manifest — `PosSyncController::manifest()` | `pos_manifest:{storeId}` | 30–60 s | Time-based |
| Report cache — `BaseReport::remember()` | Already tenant-namespaced (line 159) | 5 min / 60 min | Switch driver from `array` to `database` or `redis` |

**Critical note on `BaseReport::remember()`:** `Cache::driver('array')` lives only for the duration of a single PHP process. Every HTTP request re-executes all report DB queries regardless of TTL. Configure a dedicated cache store in `config/cache.php` using the `database` or `redis` driver and reference it as `Cache::store('db')->remember(...)`.

---

### Report Query Concerns

| Location | Concern |
|----------|---------|
| `routes/console.php` — `reports:dispatch-scheduled` (line 586) | Loads ALL active scheduled reports per tenant into memory via `->get()->filter->isDue()`. Inside the `foreach`, each due report calls `$report->run($filters)` synchronously. With 50 active stores and 5 scheduled reports each, this is 250 full report executions per 15-minute scheduler window. |

**Recommendations:**
1. Add a `next_run_at` column to `scheduled_reports` and filter with `->where('next_run_at', '<=', now())` to push `isDue()` into the database.
2. Dispatch each due report as a queued job (`App\Jobs\RunScheduledReport`) instead of running synchronously.
3. Increase the schedule interval from 15 minutes to 30 minutes while reports are still logged-only.

---

### Queue Performance Notes

| Job / Command | Schedule | Current Issue | Recommendation |
|---------------|----------|---------------|----------------|
| `reports:dispatch-scheduled` | Every 15 min | Synchronous per-tenant report execution loop | Dispatch queued jobs; push `isDue()` to DB; increase interval to 30 min |
| `communications:reset-daily-quotas` | Every hour | `Store::chunk(20)` across ALL stores with tenant context switch and `Schema::hasTable` check per store | Pre-filter to stores whose quota reset window has elapsed; or reduce schedule to every 6 hours |
| `loyalty:birthday-bonuses` | Daily | One `LoyaltyService::applyBirthdayBonus()` call per customer — 200 birthday customers = 200+ sequential transactions | Wrap in `DB::transaction()`; pass collection of IDs to single service method; use bulk insert for `loyalty_transactions` |
| `subscriptions:retry-failed` | As scheduled | Per-item `Payment::count()` in PHP collection filter | Move count predicate to query builder before `->get()` |

---

## Step 4: Frontend Performance Findings

### Bundle Analysis

| Package | Approximate Size | Current Status |
|---------|-----------------|----------------|
| `recharts` | ~230 KB minified | Statically imported on reports page AND admin analytics page — loads unconditionally on every visit regardless of whether chart data exists |
| `framer-motion` | Substantial animation subtrees | Pulled into POS page initial bundle via static import of `PaymentModal` |
| `next/image` | Built-in | Not used — bare `<img>` tags in the two highest-traffic pages |
| `dexie` | Moderate | Appropriate for offline-first use case; no concern |
| `axios 1.7.2` | Moderate | Latest stable 1.x; no known CVEs |

Zero uses of `import dynamic from 'next/dynamic'` exist anywhere in the codebase.

---

### Code Splitting

**What should be dynamically imported (none currently are):**

| Component | Page | Reason for Lazy Load |
|-----------|------|----------------------|
| `ReceiptScreen` | `app/dashboard/pos/page.tsx` line 21 | Only visible after a sale is completed |
| `PaymentModal` | `app/dashboard/pos/page.tsx` line 22 | Only visible when the Pay button is pressed |
| `ReportChart` | `app/dashboard/reports/[slug]/page.tsx` lines 14-17 | Only renders when `result.chart_data` is truthy; keeps recharts out of all non-report pages |
| Admin analytics chart components | `app/admin/stores/[id]/analytics/page.tsx` lines 6-9 | Six recharts components imported statically; extract to `AdminAnalyticsCharts` with `dynamic(..., { ssr: false })` |

**Recommended pattern:**
```tsx
const ReceiptScreen = dynamic(() => import('@/components/pos/ReceiptScreen'), { ssr: false });
const PaymentModal  = dynamic(() => import('@/components/pos/PaymentModal'),  { ssr: false });
const ReportChart = dynamic(
  () => import('@/components/reports/ReportChart'),
  { ssr: false, loading: () => <div className="h-64 bg-muted animate-pulse rounded-xl" /> }
);
```

---

### Image Optimization

| Location | Current State | Issue |
|----------|---------------|-------|
| `app/dashboard/pos/page.tsx` lines 463-465 | Bare `<img>` tags | No `width`/`height`, no `loading="lazy"`. Up to 200 products load all images eagerly on page mount. |
| `app/dashboard/products/page.tsx` line 168 | Bare `<img>` tag | Same issue on the products catalog page. |

Fix: replace both with `<Image>` from `next/image`, `width={80}` `height={80}` `loading="lazy"`. The `next.config.mjs` `remotePatterns` already covers the backend hostname — no additional config changes needed.

---

### PWA Performance

| Area | Current State | Assessment |
|------|---------------|------------|
| API catch-all cache rule | `NetworkFirst`, 5-second timeout, 1-hour TTL, 100 entries max | Blunt — reference data shares TTL budget with transactional data |
| Reference data caching | Covered by catch-all only | Should use `StaleWhileRevalidate` with 24-hour TTL |
| PWA install screenshots | `public/manifest.json` `screenshots: []` is empty | Add 2-3 POS screenshots in portrait (1080x1920) and landscape (1920x1080) with `form_factor: 'wide'` |

**Recommended cache rule split:**
```js
{ urlPattern: /\/api\/backend\/(categories|brands|units|tax-rates|customer-groups)/,
  handler: 'StaleWhileRevalidate',
  options: { cacheName: 'pos-reference', expiration: { maxAgeSeconds: 86400 } } },
{ urlPattern: /\/api\/backend\/.*/,
  handler: 'NetworkFirst',
  options: { networkTimeoutSeconds: 5, cacheName: 'pos-api',
             expiration: { maxEntries: 100, maxAgeSeconds: 3600 } } }
```

---

### React Performance

| Issue | Location | Impact |
|-------|----------|--------|
| Sequential `for-await` payment loop | `pos/page.tsx` — `handlePayments()` line 302 | Cash + loyalty-points checkout doubles checkout latency on the critical cashier/customer path. Fix: `await Promise.all(payments.map(p => apiClient.post(...)))`. |
| 10-second polling for pending sales count | `hooks/useSyncService.ts` lines 178-183 | Always-active even when no pending sales exist. Fix: remove interval; call `getPendingCount` inside `triggerUpload` after successful upload and after `handleOfflinePayments` completes a sale. |
| Full table scan on every keystroke | `lib/offline/hooks.ts` — `useOfflineProducts` lines 44-52 | Dexie `filter()` scans up to 2000 cached products on each keystroke. Fix: 150 ms debounce on `productSearch` state in `pos/page.tsx` line 415. Long-term: add `name_lower` indexed field to Dexie schema. |
| 28 individual `useState` declarations | `pos/page.tsx` lines 37-68 | Six boolean modal states cause sequential re-renders. Fix: collapse into `const [activeModal, setActiveModal] = useState<'customer'|'payment'|...|null>(null)`. |
| Keyboard listener re-added on every cart mutation | `pos/page.tsx` — `useEffect` line 366 | `sale` in dependency array tears down and re-adds listener on every cart change. Fix: use `useRef` to hold current `sale`; change dependency array to `[]`. |
| Client component boundary too high | `app/dashboard/layout.tsx` line 1 | `'use client'` forces entire sidebar nav tree into the client bundle. Fix: extract `ClientAuthGuard` wrapper as the only Client Component. |

---

## Fixes Applied This Session

- No code changes were applied during this audit session. This document captures all findings for the development team to action as a prioritised backlog.

---

## Deferred Optimizations (Post-Launch)

- **Stripe retry N+1** (`routes/console.php` lines 369-373): Move `Payment::count()` predicate to query builder. Affects background command only, not user-facing.
- **SKU/barcode generation loops** (`ProductController.php` lines 422-449): Replace `do-while` DB existence loops with sequence-based generation or catch `IntegrityConstraintViolationException` on a single insert.
- **PWA install screenshots** (`public/manifest.json`): Add portrait and landscape screenshots to improve install prompt conversion.
- **Dashboard layout client boundary** (`app/dashboard/layout.tsx`): Refactor to push `'use client'` boundary down to auth-guard wrapper only.
- **Birthday bonus batching** (`loyalty:birthday-bonuses` command): Batch loyalty transaction inserts with `DB::transaction()` and bulk insert.
- **Bearer token in localStorage** (`store/auth.ts`): Migrate to `Secure; SameSite=Strict; HttpOnly` cookie via Laravel Sanctum cookie auth.
- **Wildcard image hostname** (`next.config.mjs` `{ hostname: '**' }`): Restrict to specific known hostnames once confirmed.

---

## Step 2: Frontend Security Summary

Full audit detail is in `SECURITY-AUDIT.md`. The findings relevant to the performance/architecture context of this document are summarised below.

### Critical

| # | Location | Issue |
|---|----------|-------|
| 1 | `package.json` line 39 | **Next.js 14.2.5 is vulnerable to CVE-2025-29927** — middleware auth-bypass via `x-middleware-subrequest` header spoofing. No `middleware.ts` exists currently so not yet exploitable, but upgrade to 14.2.29 or later is mandatory before middleware is added. Run `npm install next@latest` and `npm audit`. |
| 2 | `components/pos/ReceiptScreen.tsx` lines 53, 114 | **Two `innerHTML` sinks** write unsanitized server-fetched HTML (line 53) and unsanitized cart data (line 114) into popup windows. Directly exploitable via malicious product names in the database. HTML-escape all user-data fields (`storeName`, `offline_reference`, `customer.name`, `customer.phone`, `product_name`) before interpolation. |

### High

| # | Location | Issue |
|---|----------|-------|
| 3 | `app/(public)/login/page.tsx` lines 40-42; `app/billing/renew/page.tsx` line 95 | **Open redirect** — `router.push(redirect)` with no validation. Fix: `if (redirect && redirect.startsWith('/') && !redirect.startsWith('//')) { router.push(redirect); } else { router.push('/dashboard'); }`. |
| 4 | No `middleware.ts` | **All route protection is client-side only.** Raw page HTML visible before redirect fires. Add server-side `middleware.ts` reading auth token for `/dashboard/*` and `/admin/*` routes. |
| 5 | `store/auth.ts` lines 31-32, 79-80 | **Dual localStorage token storage** — token written manually in `setAuth()` AND by Zustand persist under key `pos-auth`. Remove the duplicate write. Long-term: migrate to `HttpOnly` cookie via Sanctum. |

### Medium

| # | Location | Issue |
|---|----------|-------|
| 6 | `app/admin/layout.tsx` lines 61-66 | Admin guard race window. Acceptable if Laravel backend enforces `is_super_admin` on every `/admin/*` endpoint. |
| 7 | `app/dashboard/layout.tsx` lines 76-80 | Super admin redirect in `useEffect` — dashboard shell briefly visible. Minor UI information leak. |
| 8 | `app/(public)/page.tsx` line 31 | `window.location.href = status.data.redirect_when_disabled` — unvalidated open redirect from API response. Validate against allow-list or restrict to relative paths. |
| 9 | `next.config.mjs` `{ hostname: '**' }` | Wildcard allows app to be used as open image proxy. Restrict to known hostnames. |
| 10 | `next.config.mjs` — no HTTP security headers | No CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, or HSTS. App can be iframed for clickjacking. Add `async headers()` function in `next.config.mjs`. |

### Positives Confirmed (Low / No Action)

- No third-party CDN-loaded scripts; fonts inlined at build time via `next/font/google`.
- No non-public environment variables in any `app/` or `lib/` file.
- No `console.log` token or credential leaks anywhere in the codebase.
- No `dangerouslySetInnerHTML` in the React rendering tree.
