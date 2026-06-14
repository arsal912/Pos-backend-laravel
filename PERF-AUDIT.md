# Performance Audit Report — Phase 7 Steps 3-4

## Executive Summary

The POS platform has four confirmed N+1 query patterns and two critical unoptimised dashboard endpoints that execute 18+ sequential database queries on every page load with no caching, making backend performance the most urgent concern. On the frontend, the absence of any dynamic imports causes the entire JS bundle — including a ~230 KB recharts dependency — to load on every page visit regardless of whether chart components are ever rendered. Three database indexes required by the Phase 7 spec are missing and must be created before production deployment.

---

## Step 3: Backend Performance Findings

### N+1 Query Risks

| # | File | Issue | Recommended Fix |
|---|------|-------|-----------------|
| 1 | `app/Http/Controllers/Api/Store/Catalog/ProductController.php` — `index()` lines 78-81 | After paginating products, `transform()` calls `$p->totalStock()` on every row, executing one `SELECT SUM(quantity)` per product. 20 products per page = 21 queries; 100 per page = 101 queries. | Collect all paginated product IDs, run a single `InventoryItem::whereIn('product_id', $ids)->groupBy('product_id')->selectRaw('product_id, SUM(quantity) as qty')->pluck('qty','product_id')`, then map results into the transform closure by key. |
| 2 | `app/Http/Middleware/ModuleAccess.php` + `User::hasModuleAccess()` + `Store::hasModuleAccess()` | Every route guarded by `module:slug` fires 2-3 chained DB queries (two `whereHas` subqueries and a potential `activeSubscription->plan->hasModule()` chain) on every single request, with no caching. | Cache the access decision: `Cache::remember("module_access:{$user->id}:{$moduleSlug}", 300, fn() => ...)`. Invalidate via Model Observer on `StoreModule` and `UserModule` create/update events. |
| 3 | `app/Http/Controllers/Api/Store/Pos/PosController.php` — `completeSale()` line 332 | A bare `Customer::find($sale->customer_id)` executes outside the eager-load chain whenever a credit payment exists. On line 393, `$sale->fresh(['items.product', 'payments', 'customer'])` issues a full model reload. The `items.variant` relationship is never eager-loaded despite the receipt view potentially needing it. | Add `'customer'` and `'items.variant'` to the initial `with()` call on line 310. Replace the bare `Customer::find()` with `$sale->customer`. Audit whether `fresh()` on line 393 is necessary given the in-transaction state is already correct. |
| 4 | `app/Http/Controllers/Api/Store/CustomerController.php` — `index()` lines 21-31 | `Customer::query()` has no eager loading. If the API resource serialises `customer_group`, every customer row triggers a lazy-load, producing N queries for N customers in the paginated result. | Add `->with('group:id,name,slug,default_discount_percent')` to the query. Confirm no `$appends` or API resource auto-accesses the relationship without the eager load being present. |

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
| api_loggings indexes (5 total) | `api_loggings` (central DB) | Various — see audit | Already exist |

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

**Context on partial indexes already present:**
- `sales`: `(customer_id, sale_date)` exists but lacks `status`; `(branch_id, sale_date, status)` covers the branch dimension but not per-customer status queries.
- `stock_movements`: `(branch_id, created_at)` exists but `type` is absent, making branch+type filter queries do a partial index scan.
- `communication_logs`: `(customer_id, created_at)` and `(channel, status, created_at)` exist as separate indexes but the three-column composite for customer-channel-time range queries is absent.

---

### Cache Opportunities

| Endpoint / Layer | Recommended Cache Key | TTL | Invalidation Strategy |
|------------------|-----------------------|-----|-----------------------|
| Admin dashboard stats — `DashboardController::index()` | `admin:dashboard:stats` | 60 s | Time-based only (stats change at most once per minute) |
| Module access decision — `ModuleAccess` middleware | `module_access:{userId}:{moduleSlug}` | 300 s | Model Observer on `StoreModule` / `UserModule` create/update/delete |
| POS reference data (categories, brands, units, tax rates, customer groups) — `PosSyncController::reference()` | `pos_reference:{storeId}` | 600 s (10 min) | Observer on any Category, Brand, Unit, TaxRate, CustomerGroup mutation for the tenant |
| POS sync manifest — `PosSyncController::manifest()` | `pos_manifest:{storeId}` | 30–60 s | Time-based; acceptable stale window for incremental sync decisions |
| Report cache — `BaseReport::remember()` | Already tenant-namespaced (line 159) | 5 min (real-time), 60 min (historical) | Switch driver from `array` (per-process, non-persistent) to `database` or `redis` — current implementation silently discards all cached results across requests |

**Critical note on `BaseReport::remember()`:** `Cache::driver('array')` lives only for the duration of a single PHP process. Every HTTP request that runs a report re-executes all underlying DB queries regardless of the TTL set. The fix is to configure a dedicated cache store in `config/cache.php` using the `database` or `redis` driver and reference it as `Cache::store('db')->remember(...)` inside `BaseReport`. The existing tenant-namespaced key already prevents cross-tenant leakage.

**Admin dashboard anti-patterns (DashboardController lines 22-92):**
- 18 separate sequential `COUNT`/`SUM` queries run on every load with no caching.
- `StoreAggregate::all()` on line 92 loads every aggregate row into PHP memory for in-memory arithmetic.
- A second identical `StoreAggregate` query executes on line 101 for the top-stores chart.

Fixes: wrap the entire response in `Cache::remember()` with a 60-second TTL; replace `StoreAggregate::all()` with a single `DB::table('store_aggregates')->selectRaw('SUM(...) as x, ...')` aggregation query; reuse the line-92 collection for the line-101 chart rather than re-querying.

---

### Report Query Concerns

| Location | Concern |
|----------|---------|
| `routes/console.php` — `reports:dispatch-scheduled` (line 586) | Loads ALL active scheduled reports per tenant into memory via `->get()->filter->isDue()`. The `isDue()` check runs as a PHP-side collection filter rather than a DB-side predicate. Inside the `foreach`, each due report calls `$report->run($filters)`, executing the full report query synchronously. With 50 active stores and 5 scheduled reports each, this is 250 full report executions per 15-minute scheduler window. |

**Recommendations:**
1. Add a `next_run_at` column to `scheduled_reports` and filter with `->where('next_run_at', '<=', now())` to push the `isDue()` check into the database.
2. Dispatch each due report as a queued job (`App\Jobs\RunScheduledReport`) instead of running synchronously in the scheduler loop.
3. Increase the schedule interval from 15 minutes to 30 minutes while reports are still logged-only with no consumer-facing delivery SLA.

---

### Queue Performance Notes

| Job / Command | Schedule | Current Issue | Recommendation |
|---------------|----------|---------------|----------------|
| `reports:dispatch-scheduled` | Every 15 min | Synchronous per-tenant report execution loop — see Report Query Concerns above | Dispatch queued jobs; push `isDue()` to DB; increase interval to 30 min |
| `communications:reset-daily-quotas` | Every hour | Iterates `Store::chunk(20)` across ALL stores, performing a tenant DB context switch and `Schema::hasTable` check per store for a reset event that only needs to happen once per 24-hour window | Pre-filter to only stores whose quota reset window has elapsed (add a `quota_resets_before` column or track centrally). Alternatively reduce schedule to every 6 hours. |
| `loyalty:birthday-bonuses` | Daily | `Customer::whereRaw(...)->each()` calls `LoyaltyService::applyBirthdayBonus($c->id)` one customer at a time — each call likely issues individual DB insert/update operations. 200 birthday customers = 200+ sequential transactions. | Wrap all operations for a single store in `DB::transaction()`. Pass a collection of customer IDs to a single service method. Use bulk insert / `upsert` for `loyalty_transactions`. |
| `subscriptions:retry-failed` | As scheduled | In-memory `filter()` with per-item `Payment::count()` — see N+1 section above | Move count predicate to query builder before `->get()` |

---

## Step 4: Frontend Performance Findings

### Bundle Analysis

| Package | Approximate Size | Current Status |
|---------|-----------------|----------------|
| `recharts` | ~230 KB minified | Statically imported on reports page AND admin analytics page — loads unconditionally on every page visit regardless of whether chart data exists |
| `framer-motion` | Substantial animation subtrees | Pulled into the POS page initial bundle via static import of `PaymentModal` |
| `next/image` | Built-in | Not used — bare `<img>` tags in the two highest-traffic pages |
| `dexie` | Moderate | Appropriate for offline-first use case; no concern |
| `axios 1.7.2` | Moderate | Latest stable 1.x; no known CVEs |

Zero uses of `import dynamic from 'next/dynamic'` exist anywhere in the codebase. All components are statically imported, meaning every page's full dependency tree is included in the initial JS bundle parsed on page load.

---

### Code Splitting

**What should be dynamically imported (none currently are):**

| Component | Page | Reason for Lazy Load |
|-----------|------|----------------------|
| `ReceiptScreen` | `app/dashboard/pos/page.tsx` line 21 | Only visible after a sale is completed — never on initial page load |
| `PaymentModal` | `app/dashboard/pos/page.tsx` line 22 | Only visible when the Pay button is pressed |
| `ReportChart` | `app/dashboard/reports/[slug]/page.tsx` lines 14-17 | Only renders when `result.chart_data` is truthy; keeps recharts (~230 KB) out of all non-report pages |
| Admin analytics chart components | `app/admin/stores/[id]/analytics/page.tsx` lines 6-9 | Six recharts components imported statically; should be extracted to a `AdminAnalyticsCharts` component loaded with `dynamic(..., { ssr: false })` conditionally on `analyticsData` being non-null |

**Recommended dynamic import pattern:**

```tsx
// pos/page.tsx
const ReceiptScreen = dynamic(() => import('@/components/pos/ReceiptScreen'), { ssr: false });
const PaymentModal  = dynamic(() => import('@/components/pos/PaymentModal'),  { ssr: false });

// reports/[slug]/page.tsx
const ReportChart = dynamic(
  () => import('@/components/reports/ReportChart'),
  { ssr: false, loading: () => <div className="h-64 bg-muted animate-pulse rounded-xl" /> }
);
```

Both modal components are shown only after explicit user action, so lazy-loading them carries zero UX cost.

---

### Image Optimization

| Location | Current State | Issue |
|----------|---------------|-------|
| `app/dashboard/pos/page.tsx` lines 463-465 | Bare `<img>` tags | No `width`/`height`, no `loading="lazy"`. Grid can render up to 200 products — all images load eagerly on page mount, causing a large network and decode waterfall. |
| `app/dashboard/products/page.tsx` line 168 | Bare `<img>` tag | Same issue on the products catalog page. |

**Fix for both locations:** Replace with Next.js `<Image>` component (`next/image`) with `width={80}` `height={80}` and `loading="lazy"`. Next.js Image auto-generates `srcset`, converts to WebP/AVIF, and defers offscreen images. The `next.config.mjs` `remotePatterns` already covers the backend hostname, so no additional config changes are needed.

**Note on `next.config.mjs` wildcard image pattern:** `{ protocol: 'https', hostname: '**' }` allows Next.js image optimisation to proxy images from any HTTPS host, which can be abused as an open image proxy. Restrict to specific known hostnames (backend domain, CDN/S3 bucket hostname) and remove the wildcard entry.

---

### PWA Performance

| Area | Current State | Assessment |
|------|---------------|------------|
| API catch-all cache rule | `NetworkFirst` with 5-second timeout, 1-hour TTL, 100 entry max for `/api/backend/.*` | Blunt — reference data (categories, brands, units, tax rates) shares the same 1-hour TTL and max-entries budget as transactional data (sales, inventory). |
| Reference data caching | Covered by catch-all rule only | Should use `StaleWhileRevalidate` with 24-hour TTL — these endpoints change only when an admin edits them, not between sessions. |
| PWA install screenshots | `public/manifest.json` screenshots array is empty | Chrome 109+ and Edge use screenshots in the install prompt. Empty array results in a minimal install prompt with lower install conversion. Add 2-3 POS interface screenshots in portrait (1080x1920) and landscape (1920x1080) with `form_factor: 'wide'` for landscape. |
| Sync polling intervals | Three `setInterval` timers: 5-minute full sync, 30-second upload, 10-second pending sales count | The 10-second poll for `pendingSales` badge count runs unconditionally even when the cashier is idle. See React Performance section. |

**Recommended PWA cache rule split:**

```js
// StaleWhileRevalidate for reference data (changes only on admin edit)
{ urlPattern: /\/api\/backend\/(categories|brands|units|tax-rates|customer-groups)/, handler: 'StaleWhileRevalidate', options: { cacheName: 'pos-reference', expiration: { maxAgeSeconds: 86400 } } },

// NetworkFirst for transactional data (existing rule, kept)
{ urlPattern: /\/api\/backend\/.*/, handler: 'NetworkFirst', options: { networkTimeoutSeconds: 5, cacheName: 'pos-api', expiration: { maxEntries: 100, maxAgeSeconds: 3600 } } }
```

---

### React Performance

| Issue | Location | Impact |
|-------|----------|--------|
| Sequential `for-await` payment loop | `pos/page.tsx` — `handlePayments()` line 302 | Cash + loyalty-points checkout sends two POST requests back-to-back, doubling checkout latency on the critical cashier/customer path. Fix: replace with `await Promise.all(payments.map(p => apiClient.post(...)))`. |
| 10-second polling for pending sales count | `hooks/useSyncService.ts` lines 178-183 | Always-active even when no pending sales exist. Unnecessary background work during idle periods. Fix: remove the interval; call `getPendingCount` inside `triggerUpload` after a successful upload and after `handleOfflinePayments` completes a sale — real state-change-triggered counts replace idle polling. |
| Full table scan on every keystroke | `lib/offline/hooks.ts` — `useOfflineProducts` lines 44-52 | When search is active, Dexie `filter()` runs a JS-side predicate against up to 2000 cached products on each keystroke. Fix (short-term): debounce `productSearch` state in `pos/page.tsx` (line 415 has no debounce) with a 150 ms delay using `useRef` + `setTimeout`. Long-term: add a `name_lower` indexed field to the Dexie schema. |
| 28 individual `useState` declarations | `pos/page.tsx` lines 37-68 | Six boolean modal-visibility states (`showCustomer`, `showPayment`, `showHolds`, `showDiscount`, `showShortcuts`, `showRedeem`) trigger multiple sequential re-renders when toggled. Fix: collapse into `const [activeModal, setActiveModal] = useState<'customer'|'payment'|...|null>(null)`. |
| Keyboard listener re-added on every cart mutation | `pos/page.tsx` — `useEffect` keyboard handler line 366 | `sale` in the dependency array means the event listener is torn down and re-added on every add-item, remove-item, quantity-change, or discount operation. Fix: use `useRef` to hold current `sale` value inside the handler; change dependency array to `[]`. |
| Client component boundary too high | `app/dashboard/layout.tsx` line 1 | `'use client'` on the layout forces the entire sidebar navigation tree (20 nav link definitions + lucide-react icon imports) into the client bundle. Only the auth guard and active-link state require client-side code. Fix: extract an `ClientAuthGuard` wrapper as the only Client Component; move the sidebar to a Server Component using `usePathname` only where needed. |

---

## Fixes Applied This Session

- No code changes were applied during this audit session. This document captures all findings for the development team to action as a prioritised backlog.

---

## Deferred Optimizations (Post-Launch)

The following items were identified as valid optimizations but are not blocking for the current release phase and should be addressed post-launch:

- **Stripe retry N+1** (`routes/console.php` lines 369-373): Move `Payment::count()` predicate to the query builder. Low urgency — affects a background command, not a user-facing request.
- **SKU/barcode generation loops** (`ProductController.php` lines 422-449): `do-while` loops with per-iteration DB existence checks. Replace with sequence-based generation or catch `IntegrityConstraintViolationException` on a single insert. Low collision rate in practice.
- **PWA install screenshots** (`public/manifest.json`): Add portrait and landscape screenshots to improve install prompt conversion. UX improvement only, no performance impact.
- **Dashboard layout client boundary** (`app/dashboard/layout.tsx`): Refactor to push `'use client'` boundary down to auth-guard wrapper only. Moderate complexity; deferred until post-MVP.
- **Birthday bonus batching** (`loyalty:birthday-bonuses` console command): Batch loyalty transaction inserts using `DB::transaction()` and bulk insert. Low daily impact; deferred.
- **Bearer token in localStorage** (`store/auth.ts`): Migrate to `Secure; SameSite=Strict; HttpOnly` cookie using Laravel Sanctum cookie auth to eliminate localStorage exposure. Significant refactor; acceptable for post-launch hardening.
- **`next.config.mjs` wildcard image hostname** (`{ hostname: '**' }`): Restrict to specific known hostnames. Straightforward config change; no functional risk until specific hostnames are confirmed.

---

## Step 2: Frontend Security Summary

Full audit detail is in the separate `SECURITY-AUDIT.md`. The findings relevant to the performance/architecture context of this document are summarised below.

### Critical

| # | Location | Issue |
|---|----------|-------|
| 1 | `package.json` line 39 | **Next.js 14.2.5 is vulnerable to CVE-2025-29927** — a middleware auth-bypass allowing attackers to spoof `x-middleware-subrequest` and skip all middleware-based access controls. The vulnerability is unpatched in 14.2.5. **Upgrade to 14.2.29 or later immediately.** No `middleware.ts` exists currently so the bypass is not yet exploitable, but the upgrade is mandatory before middleware is added. |
| 2 | `components/pos/ReceiptScreen.tsx` lines 53, 114 | **Two `innerHTML` sinks write unsanitized data into popup windows.** Line 53 writes server-fetched HTML with no sanitization. Line 114 builds an offline receipt from cart data (`customer name`, `product_name`, `phone`, `offline_reference`) without HTML-escaping, making it directly exploitable via malicious product names in the database (e.g., `<img onerror=alert(1)>`). |

### High

| # | Location | Issue |
|---|----------|-------|
| 3 | `app/(public)/login/page.tsx` lines 40-42, `app/billing/renew/page.tsx` line 95 | **Open redirect** on `?redirect=` parameter — `router.push(redirect)` with no validation allows `/login?redirect=https://evil.com` to forward authenticated users to external phishing sites. Fix: `if (redirect && redirect.startsWith('/') && !redirect.startsWith('//')) { router.push(redirect); } else { router.push('/dashboard'); }`. |
| 4 | No `middleware.ts` file | **All route protection is client-side** (`useEffect` + `router.push`). Raw page HTML and pre-loaded data are visible before the redirect fires. If JS fails or is disabled, guards never run. The real enforcement layer must be server-side. Add a `middleware.ts` at project root reading the `auth_token` and redirecting unauthenticated requests to `/login` for `/dashboard/*` and `/admin/*` routes. |
| 5 | `store/auth.ts` lines 31-32, 79-80; `lib/api.ts` line 20 | **Dual localStorage token storage** — the token is written manually in `setAuth()` AND serialised again by Zustand persist under key `pos-auth`. Any XSS executing on the app origin can read both. Fix: remove the duplicate write (keep either manual calls or Zustand persist, not both). Long-term: migrate to `HttpOnly` cookie via Sanctum cookie auth. |

### Medium

| # | Location | Issue |
|---|----------|-------|
| 6 | `app/admin/layout.tsx` lines 61-66 | Admin guard has a render-gap race window — children may briefly render if `isLoading` is false and `user` is null. Acceptable as long as the Laravel backend enforces `is_super_admin` on every `/admin/*` endpoint. |
| 7 | `app/dashboard/layout.tsx` lines 76-80 | Super admin redirect fires in `useEffect` — dashboard shell is briefly visible before redirect. Minor UI information leak, not a security breach. |
| 8 | `app/(public)/page.tsx` line 31 | `window.location.href = status.data.redirect_when_disabled` follows an admin-controlled URL with no validation — open redirect exploitable via MITM or compromised admin backend. Validate against an allow-list of domains or restrict to relative paths. |
| 9 | `next.config.mjs` — images `{ hostname: '**' }` | Wildcard allows the app to be used as an open image proxy. Restrict to known hostnames. |
| 10 | `next.config.mjs` — no HTTP security headers | No CSP, no `X-Frame-Options`, no `X-Content-Type-Options`, no `Referrer-Policy`, no HSTS. Without `X-Frame-Options` the app can be iframed for clickjacking. Add an `async headers()` function in `next.config.mjs`. |

### Low (Positives Confirmed)

- No third-party CDN-loaded scripts (fonts are inlined at build time via `next/font/google`).
- No non-public environment variables appear in any `app/` or `lib/` file — only `NEXT_PUBLIC_` prefixed vars are used.
- No `console.log` token or credential leaks in `store/auth.ts`, `hooks/`, or `lib/`. Single `console.error` on landing page logs a fetch error, not sensitive data.
- No `dangerouslySetInnerHTML` usage anywhere in the React tree (the `innerHTML` risks are in imperative DOM manipulation on popup windows in `ReceiptScreen.tsx`, not React's rendering path).
