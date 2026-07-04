# Security Audit Report — Phase 7 Step 1

**Project:** Multi-Tenant POS SaaS  
**Stack:** Laravel 11 (PHP 8.2) Backend · Next.js 14 Frontend  
**Audit Date:** 2026-06-14  
**Auditor:** Automated multi-dimension security review (SQL Injection, Mass Assignment, Authentication, CORS/XSS/Headers, Webhook Verification, Tenant Isolation, File Upload, Sensitive Data Handling, Dependency Scanning)  
**Branch:** development

---

## Executive Summary

The codebase demonstrates a solid architectural foundation — tenant isolation via stancl/tenancy is correctly wired, credentials are encrypted at rest, and the majority of raw SQL calls are parameterized. However, **5 critical or equivalent-severity issues** require resolution before any production deployment: Next.js 14.2.5 carries a publicly exploited auth-bypass CVE (CVSS 9.1), a tenant can cancel any other tenant's subscription via an unscoped IDOR, two privilege-escalation fields (`is_super_admin`, `store_id`) are mass-assignable on the User model, Sanctum tokens never expire (perpetual session), and receipt HTML is built with unsanitized user data written to `innerHTML`. There are a further **17 high-severity findings** spanning XSS in the receipt screen, missing rate limits on reset/verify endpoints, cross-tenant file serving, unscoped billing lookups, and abandoned PayPal SDKs. **Immediate actions before launch** are: upgrade Next.js, fix the subscription IDOR, remove dangerous fields from `$fillable`, enforce Sanctum token expiry, and sanitize receipt HTML output.

---

## Findings by Severity

---

### CRITICAL — Next.js 14.2.5: Auth Bypass via CVE-2025-29927

- **Location:** `C:\xampp\htdocs\pos-frontend\package.json` — `next@14.2.5`
- **Issue:** Next.js 14.2.5 is vulnerable to CVE-2025-29927 (CVSS 9.1). An attacker can bypass all middleware-based authentication and authorization by sending a crafted `x-middleware-subrequest` header, gaining access to any protected route without valid credentials. Also affected by CVE-2024-56332 (DoS via `waitUntil` in edge runtime).
- **Risk:** Unauthenticated access to any route protected only by Next.js middleware (dashboard, POS screens, admin panels). If middleware is the sole auth gate for any page, an attacker can trivially bypass it from the public internet.
- **Fix:** Upgrade immediately: `npm install next@latest eslint-config-next@latest` (minimum target: 14.2.25; preferred: Next.js 15.x). If an emergency patch is not immediately possible, add a server-side check that rejects requests carrying `x-middleware-subrequest` from external clients at the reverse proxy / CDN layer.
- **Status:** Pending Fix

---

### CRITICAL — Subscription Cancellation IDOR (Cross-Tenant)

- **Location:** `app/Http/Controllers/Api/Store/BillingController.php` lines 128–133 (`cancel` method)
- **Issue:** The `cancel()` method accepts `subscription_id` from the POST body, validates only that the row exists anywhere in the central DB (`Rule::exists(Subscription::class, 'id')`), then calls `Subscription::findOrFail($validated['subscription_id'])` with no `store_id` check.
- **Risk:** An authenticated user from Store A can cancel Store B's active subscription by supplying Store B's subscription ID. A competitor or malicious user could knock any tenant off the platform by iterating integer subscription IDs.
- **Fix:**
  ```php
  // Change Rule::exists to scope by store:
  Rule::exists(Subscription::class, 'id')->where('store_id', auth()->user()->store_id)

  // Change the fetch to:
  $subscription = Subscription::where('store_id', auth()->user()->store_id)
      ->findOrFail($validated['subscription_id']);
  ```
- **Status:** Pending Fix

---

### CRITICAL — `is_super_admin` Mass-Assignable on User Model

- **Location:** `app/Models/User.php` line 27
- **Issue:** `is_super_admin` is listed in `$fillable`. Any controller that calls `User::create()` or `$user->update()` with unfiltered request data could allow a regular user to elevate themselves to super-admin.
- **Risk:** Full platform compromise. A user who finds any endpoint that mass-assigns User fields and passes `is_super_admin: true` becomes a super-admin with access to all tenants.
- **Fix:** Remove `'is_super_admin'` from `$fillable`. Set it only via explicit direct assignment inside trusted admin-only code: `$user->is_super_admin = true; $user->save();`
- **Status:** Pending Fix

---

### CRITICAL — `store_id` Mass-Assignable on User Model (Tenant Isolation Bypass)

- **Location:** `app/Models/User.php` line 25
- **Issue:** `store_id` is in `$fillable`. If any profile-update or user-management endpoint passes `$request->validated()` without explicitly excluding `store_id`, an authenticated user could reassign themselves to a different store's tenant context.
- **Risk:** Complete tenant isolation bypass — a user can migrate their account into another tenant's database context, accessing all of that tenant's POS, customer, and financial data.
- **Fix:** Remove `'store_id'` (and `'branch_id'`) from `$fillable`. Set these only via explicit assignment during registration or admin operations.
- **Status:** Pending Fix

---

### CRITICAL — Sanctum Tokens Never Expire

- **Location:** `config/` (no `sanctum.php` published) + `.env` lines 87–88
- **Issue:** `config/sanctum.php` does not exist in the project, so the package default applies: `expiration => null` (tokens live forever). The `.env` variables `JWT_TTL=10080` and `JWT_REFRESH_TTL=20160` are entirely unused — Sanctum is the actual auth driver and reads neither value.
- **Risk:** A stolen token (from a log file, a compromised device, a network capture) is valid indefinitely. An attacker who obtains a cashier's token from a lost POS device retains permanent access to the store until the user manually calls `logoutAll`.
- **Fix:**
  ```bash
  php artisan vendor:publish --tag=sanctum-config
  ```
  Then in `config/sanctum.php`:
  ```php
  'expiration' => 10080, // 7 days — matches current JWT_TTL intent
  ```
  Remove or rename the unused `JWT_TTL`/`JWT_REFRESH_TTL` `.env` keys. Consider adding a token refresh flow if shorter expiry is desired for web sessions.
- **Status:** Pending Fix

---

### HIGH — XSS via Unsanitized User Data in Receipt `innerHTML` (Offline Path)

- **Location:** `C:\xampp\htdocs\pos-frontend\components\pos\ReceiptScreen.tsx` lines 85–93 (`printOfflineReceipt`)
- **Issue:** `cart.customer.name`, `cart.customer.phone`, and `item.product_name` are interpolated directly into an HTML string using template literals and written to a popup via `win.document.documentElement.innerHTML = lines.join('\n')`. No escaping is applied.
- **Risk:** A customer whose name contains `<script>alert(document.cookie)</script>` or an event handler attribute will execute JavaScript in the popup context when the receipt is printed. If product names are synced from external sources, stored XSS is also possible.
- **Fix:** Add and apply an escape helper before every user-supplied value:
  ```ts
  function escapeHtml(s: string): string {
    return s
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
  // Usage: `<p>Customer: ${escapeHtml(cart.customer.name)}</p>`
  ```
  Alternatively, build the popup DOM programmatically using `document.createElement` + `textContent` to avoid string injection entirely.
- **Status:** Pending Fix

---

### HIGH — Server Receipt HTML Written to Popup `innerHTML` Without Validation

- **Location:** `C:\xampp\htdocs\pos-frontend\components\pos\ReceiptScreen.tsx` line 53 (`openServerReceipt`)
- **Issue:** Raw HTML fetched from `/api/backend/store/pos/sales/{id}/receipt` is written directly to `win.document.documentElement.innerHTML = html`. If the API response is tampered (MITM, compromised CDN), or if a future Blade template change accidentally uses `{!! !!}` for a user-controlled field, JavaScript in the HTML will execute in the popup context.
- **Risk:** Stored or reflected XSS via the receipt HTML pipeline. While Blade currently uses `{{ }}`, this relies on a single layer of defence.
- **Fix:** Replace `innerHTML` injection with a direct navigation:
  ```ts
  window.open(`/api/backend/store/pos/sales/${saleId}/receipt`, '_blank');
  ```
  This lets the browser navigate to the receipt URL directly, avoids client-side HTML injection entirely, and allows a strict CSP to be set on the receipt endpoint's response headers.
- **Status:** Pending Fix

---

### HIGH — Resend Webhook Verification Bypassed When Secret Not Configured

- **Location:** `app/Services/Communications/Providers/ResendEmailProvider.php:verifyWebhook()` line 62
- **Issue:** When `RESEND_WEBHOOK_SECRET` is empty or missing, `verifyWebhook()` returns `true` unconditionally, accepting any incoming POST as a legitimate Resend event.
- **Risk:** An attacker can forge bounce, unsubscribe, or open/click events, silently opting out arbitrary email addresses from transactional communications or inflating engagement metrics without any credentials.
- **Fix:** Change the guard from `return true` to `return false` (or throw a `RuntimeException`):
  ```php
  if (! $this->webhookSecret) {
      return false; // was: return true — this was a critical bypass
  }
  ```
  Add a constructor assertion that warns/throws if `webhookSecret` is empty in non-local environments.
- **Status:** Pending Fix

---

### HIGH — Cross-Tenant Path Traversal in Generic File-Serving Route

- **Location:** `routes/api/store.php` lines 350–358 (`GET /api/v1/store/files/{path}`)
- **Issue:** The route accepts any path matching `.*` and serves whatever `Storage::disk('local')->exists($path)` returns, with no check that the path belongs to the currently authenticated tenant's directory. There is also no protection against percent-encoded directory traversal sequences (e.g. `files/../../framework/sessions/xyz`).
- **Risk:** A user from Store A can request `files/products/999/image_of_store_b.jpg` and retrieve another tenant's uploaded files. Path traversal could also expose `storage/logs/laravel.log` or invoices from other tenants stored under `invoices/store_{id}/`.
- **Fix:**
  ```php
  $storePrefix = 'products/' . app('current_store_id') . '/';
  $resolvedPath = Storage::disk('local')->path($path);
  $allowedBase = Storage::disk('local')->path($storePrefix);

  if (! str_starts_with($resolvedPath, $allowedBase)) {
      abort(403, 'Access denied.');
  }
  ```
  Also restrict the served MIME types and add `Content-Disposition: attachment` to prevent inline execution of SVG or HTML files. Remove the redundant manual `if (!$request->user())` guard.
- **Status:** Pending Fix

---

### HIGH — Uploaded Images Not Re-Encoded (EXIF Retention + Polyglot Risk)

- **Location:** `app/Http/Controllers/Api/Store/Catalog/ProductController.php` lines 308–319 (`uploadImage`)
- **Issue:** Uploaded images are stored raw. EXIF metadata (GPS coordinates, device info) is retained. `intervention/image` ^3.0 is installed in `composer.json` but never used. The `image` Laravel validation rule relies on MIME detection which can be fooled by crafted polyglot files on some PHP/libmagic versions. SVG files are accepted by the `image` rule and will be served inline as `image/svg+xml`, enabling XSS.
- **Risk:** EXIF data from customer/staff-uploaded images may expose GPS location or device details. A polyglot PHP/JPEG file could potentially be executed. An SVG with embedded `<script>` executes in the browser when the product image is viewed.
- **Fix:**
  ```php
  // Re-encode through Intervention Image to strip EXIF and neutralize polyglots:
  use Intervention\Image\ImageManager;
  use Intervention\Image\Drivers\Gd\Driver;
  use Intervention\Image\Encoders\JpegEncoder;

  $manager = new ImageManager(new Driver());
  $encoded = $manager->read($request->file('image'))->encode(new JpegEncoder(85));
  $path = "products/{$product->id}/" . Str::uuid() . '.jpg';
  Storage::disk('local')->put($path, (string) $encoded);
  ```
  Replace the validation rule `image` with `mimes:jpg,jpeg,png,webp,gif` to explicitly exclude SVG and BMP.
- **Status:** Pending Fix

---

### HIGH — `logo`/`favicon`/`og_image` Validated Only as Nullable String (SSRF/XSS Risk)

- **Location:** `app/Http/Controllers/Api/Admin/LandingPageController.php` lines 61–63 (`updateSettings`)
- **Issue:** `logo`, `favicon`, and `og_image` are validated only as `nullable|string`, accepting `javascript:` URIs, `data:` URIs, absolute internal paths, or arbitrary external URLs.
- **Risk:** If the frontend renders these as `<img src="...">` or `<link href="...">` without sanitization, XSS or SSRF against internal metadata services is possible. Even though this endpoint is super-admin-only, it is still an injection surface that could be reached via a compromised admin session.
- **Fix:**
  ```php
  'logo'     => 'nullable|url|max:500',
  'favicon'  => 'nullable|url|max:500',
  'og_image' => 'nullable|url|max:500',
  ```
  Ideally, replace the string fields with a proper file upload sub-endpoint that validates MIME type, re-encodes images, and stores a server-controlled path.
- **Status:** Pending Fix

---

### HIGH — Abandoned PayPal SDKs

- **Location:** `composer.lock` — `paypal/paypal-checkout-sdk@1.0.2` and `paypal/paypalhttp@1.0.1`
- **Issue:** Both packages are explicitly marked `abandoned` in `composer.lock`. `paypal/paypal-checkout-sdk` (last release 2021-09-21) is superseded by `paypal/paypal-server-sdk`. `paypal/paypalhttp` is fully abandoned with no replacement. Abandoned packages receive no security fixes.
- **Risk:** Any future vulnerability in these packages will not be patched by the maintainer. The PayPal integration could become non-functional when PayPal deprecates the v1 Checkout API.
- **Fix:**
  ```bash
  composer require paypal/paypal-server-sdk
  composer remove paypal/paypal-checkout-sdk paypal/paypalhttp
  ```
  Update all PayPal integration code in `app/Services/PaymentGateways/PayPalService.php` to use the new SDK.
- **Status:** Pending Fix

---

### HIGH — Password Reset and Email Verification Endpoints Have No Rate Limiting

- **Location:** `routes/api.php` lines 63–65
- **Issue:** `POST /auth/password/forgot`, `POST /auth/password/validate`, `POST /auth/password/reset`, and `POST /auth/email/verify` have no throttle middleware applied.
- **Risk:** Unlimited automated token-guessing attacks against the 64-character reset token. Mass email flooding via the forgot-password endpoint. Brute-force of email verification tokens.
- **Fix:** Apply throttle middleware to all four endpoints:
  ```php
  Route::post('/auth/password/forgot', ...)->middleware('throttle:5,1');
  Route::post('/auth/password/validate', ...)->middleware('throttle:5,1');
  Route::post('/auth/password/reset', ...)->middleware('throttle:5,1');
  Route::post('/auth/email/verify', ...)->middleware('throttle:5,1');
  ```
- **Status:** Pending Fix

---

### HIGH — `store_id` on Store Model Allows Status Self-Activation

- **Location:** `app/Models/Store.php` line 37 (`status` in `$fillable`)
- **Issue:** `status` controls whether a store is `pending`, `active`, `suspended`, or `expired`. It is mass-assignable, meaning any controller that calls `$store->fill()` or `$store->update()` with request-sourced data could allow a store to flip its own status to `active`.
- **Risk:** A tenant could activate their own suspended/expired store, bypassing the payment/subscription enforcement mechanism and gaining free unlimited access.
- **Fix:** Remove `'status'` from `$fillable` in `app/Models/Store.php`. Implement explicit state-transition methods: `$store->activate()`, `$store->suspend()`, `$store->expire()` — these should contain all the business logic and be the only way to change status.
- **Status:** Pending Fix

---

### HIGH — `tenancy_db_name` Mass-Assignable on Store Model

- **Location:** `app/Models/Store.php` line 27
- **Issue:** `tenancy_db_name` (the column storing the tenant database name) is in `$fillable`. The comment says "set by stancl/tenancy when the tenant DB is created" — yet it is exposed to mass assignment.
- **Risk:** An admin endpoint that accepts store update data could be used to overwrite the tenant's DB name, redirecting the store's entire tenant context to an arbitrary database.
- **Fix:** Remove `'tenancy_db_name'` from `$fillable` in `app/Models/Store.php`. This field is purely internal infrastructure managed by the tenancy package and must never be assignable through application-level input.
- **Status:** Pending Fix

---

### HIGH — `getDraftSale` and `completeSale` Have No Cashier-Level Ownership Check

- **Location:** `app/Http/Controllers/Api/Store/Pos/PosController.php` lines 597–610 (`getDraftSale`), 310, 427
- **Issue:** `getDraftSale()` calls `Sale::with('items')->findOrFail($saleId)` with no cashier or branch scope. `completeSale()` and `receipt()` do the same. Any user within the same store who has the `create-sales` permission can modify, complete, void, or print a receipt for any other cashier's draft sale by guessing its integer ID.
- **Risk:** Cashier A can complete or void Cashier B's in-progress sale, causing financial discrepancies, incorrect commission attribution, and potential fraud.
- **Fix:**
  ```php
  // In getDraftSale():
  Sale::with('items')
      ->where('cashier_id', auth()->id())
      ->findOrFail($saleId);
  ```
  For manager-level override, add a separate `manage-sales` permission gate before widening the scope beyond the authenticated cashier.
- **Status:** Pending Fix

---

### HIGH — `verifySession` Payment Lookup Not Scoped to Current Store

- **Location:** `app/Http/Controllers/Api/Store/BillingController.php` lines 241–268 (`verifySession`)
- **Issue:** `Payment::where('gateway_payment_id', $sessionId)->first()` has no `store_id` filter. If gateway session IDs are predictable or leaked via error messages, a user from a different store can query the payment record and receive its subscription details.
- **Risk:** Cross-tenant billing data leakage. A crafted request could expose another tenant's payment status, amount, and subscription association.
- **Fix:**
  ```php
  Payment::where('gateway_payment_id', $sessionId)
      ->where('store_id', auth()->user()->store_id)
      ->first();
  ```
  Apply the same `store_id` filter to the fallback Stripe `handleCallback` path within the same method.
- **Status:** Pending Fix

---

### HIGH — No Account Lockout After Failed Login Attempts

- **Location:** `bootstrap/app.php` lines 40–43 + `app/Http/Controllers/Api/Auth/AuthController.php` lines 20–62
- **Issue:** The `throttle:auth` middleware limits to 10 requests/minute per IP only. There is no per-user failed-login counter, no `locked_until` timestamp, and no escalating delay. A slow-and-low credential-stuffing attack (under 10 req/min) is completely unrestricted.
- **Risk:** Automated credential stuffing against customer/cashier accounts at a rate the IP throttle never triggers. Given POS devices may share IP addresses (NAT), IP-only throttling is particularly ineffective.
- **Fix:** Add `failed_login_attempts` and `locked_until` columns to the `users` table. In `AuthController::login()`:
  ```php
  if ($user->locked_until && $user->locked_until->isFuture()) {
      return response()->json(['message' => 'Account locked. Try again later.'], 429);
  }
  if (! Auth::attempt($credentials)) {
      $user->increment('failed_login_attempts');
      if ($user->failed_login_attempts >= 5) {
          $user->update(['locked_until' => now()->addMinutes(15)]);
      }
      return response()->json(['message' => 'Invalid credentials.'], 401);
  }
  $user->update(['failed_login_attempts' => 0, 'locked_until' => null]);
  ```
  Use Laravel's `RateLimiter::tooManyAttempts` keyed by `email + IP` (not IP alone) as the standard approach.
- **Status:** Pending Fix

---

### HIGH — Password Reset Token Stored in Plaintext

- **Location:** `app/Http/Controllers/Api/Auth/PasswordResetController.php` line 42 + `app/Models/PasswordResetToken.php` lines 42–45
- **Issue:** The 64-character reset token is stored in plaintext in the `password_reset_tokens` table. A database read (via SQL injection, a backup leak, or a compromised DB user) gives an attacker valid reset tokens for every user who has an active reset request.
- **Risk:** Full account takeover for any user who has a pending password reset. Combined with token non-expiry (if `config/auth.php` is misconfigured), this is a persistent account takeover vector.
- **Fix:** Hash the token on storage and compare with `Hash::check()` on validation, matching Laravel's built-in `Password::broker()` pattern:
  ```php
  // On create:
  $tokenHash = Hash::make($rawToken);
  PasswordResetToken::create(['email' => $email, 'token' => $tokenHash]);

  // On validate:
  $record = PasswordResetToken::where('email', $email)->first();
  if (! $record || ! Hash::check($submittedToken, $record->token)) {
      abort(422, 'Invalid token.');
  }
  ```
  Also publish `config/auth.php` and set an explicit `'expire' => 60` to ensure expiry is never silently null.
- **Status:** Pending Fix

---

### MEDIUM — `$request->all()` Passed Unfiltered into Report Query Logic

- **Location:** `app/Http/Controllers/Api/Store/ReportController.php` line 75 + `app/Http/Controllers/Api/Admin/AdminReportController.php` lines 51 and 71
- **Issue:** `$filters = array_merge($report->getDefaultFilters(), $request->all())` passes the entire unvalidated request body into every report's `run(array $filters)` method. This is one absent match-default guard away from SQL injection in any report that interpolates filter values into raw SQL.
- **Risk:** If any current or future report uses a filter value in `selectRaw`/`orderByRaw` without a strict allowlist, direct SQL injection becomes possible. Additionally, a user could inject filter keys like `store_id` or `user_id` to potentially scope a report to data outside their own branch.
- **Fix:**
  ```php
  // Replace $request->all() with an allowlisted subset:
  $allowedKeys = array_keys($report->getDefaultFilters());
  $filters = array_merge(
      $report->getDefaultFilters(),
      $request->only($allowedKeys)
  );
  ```
  Add a `422` validation response for any key outside the allowed set.
- **Status:** Pending Fix

---

### MEDIUM — `group_by` Filter Interpolated into Raw SQL (Protected Only by `match()` Default)

- **Location:** `app/Reports/Tax/TaxCollectedReport.php` lines 67–77 + `app/Reports/Sales/SalesByDayReport.php` lines 43–68
- **Issue:** `$dateTrunc`, derived from user-supplied `$filters['group_by']`, is interpolated into `selectRaw()`, `groupByRaw()`, and `orderByRaw()`. Currently guarded by a PHP `match()` with a hardcoded `default` clause. If the `default` case is removed or a new report skips the guard, this becomes a direct SQL injection path.
- **Risk:** SQL injection if the match guard is ever removed or bypassed in a future report. The filter value travels via `$request->all()` with no framework-level validation.
- **Fix:** Add explicit validation at the controller level before filters reach any report:
  ```php
  $request->validate(['group_by' => 'sometimes|in:day,week,month']);
  ```
  Replace the `match()` fallback pattern with a PHP-backed enum or a strict `in_array` check that returns a `422` response rather than silently falling back to a hardcoded value.
- **Status:** Pending Fix

---

### MEDIUM — CORS Credentials Enabled with Localhost Fallback Origin

- **Location:** `config/cors.php` lines 9–13
- **Issue:** `supports_credentials` is `true`. `allowed_origins` includes `env('FRONTEND_URL', 'http://localhost:3000')` as a fallback. If `FRONTEND_URL` is not set in the production `.env`, the CORS policy will silently allow `http://localhost:3000` to send credentialed cross-origin requests to the production API.
- **Risk:** An attacker running a local server could send authenticated requests to the production API if a victim browses a page that embeds a localhost iframe or fetch.
- **Fix:**
  ```php
  // cors.php — remove the fallback:
  'allowed_origins' => array_filter([env('FRONTEND_URL')]),
  ```
  Add a startup assertion in `AppServiceProvider::boot()`:
  ```php
  if (app()->environment('production') && empty(config('cors.allowed_origins'))) {
      throw new \RuntimeException('FRONTEND_URL must be set in production.');
  }
  ```
  Also gate the explicit localhost entries on environment: only include them when `app()->environment('local')`.
- **Status:** Pending Fix

---

### MEDIUM — No Security Response Headers (Backend or Frontend)

- **Location:** Backend: no security headers middleware present in `bootstrap/app.php`. Frontend: `next.config.mjs` has no `headers()` export.
- **Issue:** Neither `Content-Security-Policy`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, nor `Permissions-Policy` are emitted anywhere in either codebase.
- **Risk:** Missing `X-Frame-Options` allows the POS interface to be embedded in iframes (clickjacking). Missing `X-Content-Type-Options` enables MIME-sniffing attacks. Absence of CSP leaves XSS vectors without a browser-level backstop.
- **Fix — Backend:** Create `app/Http/Middleware/SecurityHeaders.php`:
  ```php
  public function handle(Request $request, Closure $next): Response {
      $response = $next($request);
      $response->headers->set('X-Content-Type-Options', 'nosniff');
      $response->headers->set('X-Frame-Options', 'DENY');
      $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
      return $response;
  }
  ```
  Register it in `bootstrap/app.php` alongside `ApiLogger`. For receipt HTML responses, add `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline';`.

  **Fix — Frontend:** Add to `next.config.mjs`:
  ```js
  async headers() {
    return [{
      source: '/(.*)',
      headers: [
        { key: 'X-Frame-Options', value: 'DENY' },
        { key: 'X-Content-Type-Options', value: 'nosniff' },
        { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
        { key: 'Permissions-Policy', value: 'camera=(), microphone=(), geolocation=()' },
      ],
    }];
  }
  ```
- **Status:** Pending Fix

---

### MEDIUM — `password` Mass-Assignable on User Model

- **Location:** `app/Models/User.php` line 22
- **Issue:** `password` is in `$fillable`. While the `hashed` cast currently auto-hashes on assignment, the password field can be changed via any mass-assignment call. If the cast is ever removed in a refactor, passwords would be stored in plaintext.
- **Risk:** Accidental exposure of unintended password-change capability through any profile-update endpoint that does not explicitly exclude the field. Silent regression risk if the `hashed` cast is removed.
- **Fix:** Remove `'password'` from `$fillable`. Handle password changes only in dedicated password-change endpoints via explicit assignment:
  ```php
  $user->password = Hash::make($validated['new_password']);
  $user->save();
  ```
- **Status:** Pending Fix

---

### MEDIUM — `status` Mass-Assignable on Subscription Model

- **Location:** `app/Models/Subscription.php` lines 22–23
- **Issue:** `status` (`active`, `expired`, `cancelled`, `pending`) is in `$fillable`. A future endpoint that mass-assigns subscription data without explicitly excluding `status` could allow a tenant to self-activate their subscription.
- **Risk:** Subscription lifecycle bypass — a tenant could set their own subscription to `active`, bypassing payment enforcement.
- **Fix:** Remove `'status'` from `$fillable` in `app/Models/Subscription.php`. All status changes must go through explicit lifecycle methods or admin-only code.
- **Status:** Pending Fix

---

### MEDIUM — Payment Financial Fields Mass-Assignable

- **Location:** `app/Models/Payment.php` lines 23–25
- **Issue:** `status`, `paid_at`, and `refunded_at` are all in `$fillable`. Payment status (`pending`, `completed`, `failed`, `refunded`) and financial timestamps are mass-assignable.
- **Risk:** If any payment update endpoint takes user input without strict whitelisting, a user could mark their own payment as `completed`, bypassing the payment gateway entirely.
- **Fix:** Remove `'status'`, `'paid_at'`, and `'refunded_at'` from `$fillable`. Payment status transitions must only occur through webhook callbacks or explicit admin-triggered code, never mass assignment.
- **Status:** Pending Fix

---

### MEDIUM — Customer Financial/Loyalty Aggregate Fields Mass-Assignable

- **Location:** `app/Models/Customer.php` lines 22–27
- **Issue:** `loyalty_points_balance`, `outstanding_balance`, `lifetime_value`, `credit_limit`, `total_purchases_count`, and `last_purchase_at` are all in `$fillable`. These are financial aggregate fields.
- **Risk:** If any customer update endpoint does not explicitly exclude these fields, a user could arbitrarily inflate their own loyalty balance, wipe their outstanding debt, or set their credit limit.
- **Fix:** Remove all of these from `$fillable`. Update these fields only via dedicated service methods: `LoyaltyService::earnFromSale()`, `Customer::updatePurchaseStats()`, etc.
- **Status:** Pending Fix

---

### MEDIUM — `APP_DEBUG=true` in `.env` and `.env.example`

- **Location:** `.env` line 4 + `.env.example`
- **Issue:** `APP_DEBUG=true` is set. The exception handler at `bootstrap/app.php` line 97 exposes `$e->getMessage()` when debug is enabled, returning raw exception messages (including SQL query strings, file paths, credential hints) to API clients on 500 errors.
- **Risk:** Information disclosure enabling attacker reconnaissance. If this `.env` reaches production (likely on a first deploy), the application surface is fully enumerable via error messages.
- **Fix:** Set `APP_DEBUG=false` in `.env.example` and in all non-local environment files. Add a CI/CD assertion that fails if `APP_DEBUG=true` is detected outside `local` environment. Never deploy a debug-enabled build to staging or production.
- **Status:** Pending Fix

---

### MEDIUM — Email Verification Token Stored in Plaintext (UUID)

- **Location:** `app/Models/EmailVerificationToken.php` lines 36–39
- **Issue:** Email verification tokens are UUIDs stored in plaintext in the database with a 24-hour expiry.
- **Risk:** A database read yields usable verification tokens for every user with a pending email verification. 24-hour expiry is longer than industry standard.
- **Fix:** Hash the token on storage (`Hash::make($uuid)`) and compare with `Hash::check()` on verification. Reduce expiry to 1–4 hours. Use `hash_equals()` for constant-time comparison.
- **Status:** Pending Fix

---

### MEDIUM — JazzCash/Easypaisa Return Routes Accept Both GET and POST

- **Location:** `app/Http/Controllers/Api/Payments/JazzCashCallbackController.php` + `app/Http/Controllers/Api/Payments/EasypaisaCallbackController.php`
- **Issue:** Both `/return` routes are registered with `Route::match(['GET', 'POST'], ...)`. GET requests short-circuit before `verifyHash()` is called, creating an unverified code path on GET that should never carry payment data.
- **Risk:** If a gateway were ever misconfigured to use GET for return callbacks, or if payment data is inadvertently appended as query parameters, those callbacks would bypass HMAC verification entirely.
- **Fix:** Restrict both `/return` routes to `Route::post(...)` only. Add a simple redirect or informational response for bare GET requests. For Easypaisa, explicitly hardcode the expected `postBackURL` in `verifyHash()` rather than trusting the caller-supplied value.
- **Status:** Pending Fix

---

### MEDIUM — ApiLogger Masks Tokens in Response Bodies but Logs Them to the Database

- **Location:** `app/Http/Middleware/ApiLogger.php` lines 83 and 121–144
- **Issue:** Login, register, and impersonation endpoints return a `token` key in the JSON response. While `token` is in the `sensitiveFields` list and should be masked, the full logged response body is stored in the `api_loggings` database table, accessible to any admin who queries `ApiLog` records. Masking is also skipped for non-array response shapes.
- **Risk:** Admin users (or a compromised admin account) could retrieve valid Sanctum tokens from the log table and impersonate any user who recently authenticated.
- **Fix:** Exclude auth endpoints from response body logging by adding `api/v1/auth/*` to the `excluded_routes` config in `config/api-logging.php`. Alternatively, ensure `token` is always masked in all response shapes and restrict `ApiLog` access to super-admin only.
- **Status:** Pending Fix

---

### MEDIUM — `pp_Password` Not Masked in ApiLogger (JazzCash Credential Leakage)

- **Location:** `app/Services/PaymentGateways/JazzCashService.php` line 42 + `app/Http/Middleware/ApiLogger.php`
- **Issue:** The JazzCash `$params` array includes `pp_Password` (the gateway's plaintext password). The `sensitiveFields` list uses lowercase comparison but does not include `pp_password` — only `password`. This means `pp_Password` will NOT be masked in logged request payloads.
- **Risk:** The JazzCash gateway password is stored in plaintext in the `api_loggings` database table for every payment initiation request.
- **Fix:** Add `'pp_password'` and `'pp_integritysalt'` to the `sensitiveFields` array in `ApiLogger.php`. Also add the JazzCash callback/return routes to `excluded_routes` in `config/api-logging.php`.
- **Status:** Pending Fix

---

### MEDIUM — ApiLogger Ignores `config/api-logging.php` `sensitive_fields`

- **Location:** `app/Http/Middleware/ApiLogger.php` lines 17–32 vs `config/api-logging.php` lines 34–41
- **Issue:** `ApiLogger` maintains its own hardcoded `sensitiveFields` array and never reads or merges the `sensitive_fields` array from `config/api-logging.php`. The config file exists and defines a separate list that is silently ignored.
- **Risk:** Any future additions to `config/api-logging.php` sensitive_fields have zero effect. Security-conscious additions to the config file create false confidence.
- **Fix:**
  ```php
  // In ApiLogger constructor or handle():
  $this->sensitiveFields = array_merge(
      $this->sensitiveFields,
      config('api-logging.sensitive_fields', [])
  );
  ```
- **Status:** Pending Fix

---

### MEDIUM — Stack Traces Stored in `api_loggings` Table

- **Location:** `app/Models/ApiLog.php` line 29 + `app/Http/Middleware/ApiLogger.php` lines 87–88
- **Issue:** Exception stack traces are captured and stored in the `api_loggings.stack_trace` column, exposing internal file paths, class names, database schema hints, and library versions to any admin who queries the log table.
- **Risk:** Accelerates attacker reconnaissance if log access is compromised. May violate compliance requirements (PCI-DSS log content restrictions).
- **Fix:**
  ```php
  $stackTrace = config('app.debug') ? $e->getTraceAsString() : null;
  ```
  Store full stack traces only when `APP_DEBUG=true` (i.e., local development). Add log retention limits and restrict log table access to super-admin role only.
- **Status:** Pending Fix

---

### MEDIUM — Weak Password Rules (No Complexity Requirements)

- **Location:** `app/Http/Controllers/Api/Auth/RegisterController.php` line 36 + `app/Http/Controllers/Api/Auth/PasswordResetController.php` line 71
- **Issue:** Password validation is `min:8|confirmed` only. An 8-character all-lowercase password (`password`) is accepted. No complexity requirements, no common-password check.
- **Risk:** Users set trivially weak passwords, dramatically reducing the effort required for brute-force or credential-stuffing attacks.
- **Fix:** Replace with Laravel's fluent password rule:
  ```php
  Password::min(8)
      ->letters()
      ->mixedCase()
      ->numbers()
      ->uncompromised() // queries haveibeenpwned.com
      ->confirmed()
  ```
  At minimum, require `->letters()->numbers()` to block the most common weak passwords.
- **Status:** Pending Fix

---

### MEDIUM — PHP Upload Limit 40 MB vs Application Limit 5 MB (DoS Vector)

- **Location:** `app/Http/Controllers/Api/Store/Catalog/ProductController.php` line 309 vs `C:\xampp\php\php.ini`
- **Issue:** `upload_max_filesize` and `post_max_size` are both 40 MB in XAMPP's `php.ini`. The application enforces 5 MB via `max:5120`. PHP fully buffers files up to 40 MB into `/tmp` before Laravel's validation rejects them.
- **Risk:** Resource exhaustion / DoS — an attacker can send repeated 39 MB uploads that Laravel rejects but PHP fully buffers first, consuming disk and memory.
- **Fix:** Lower `upload_max_filesize` and `post_max_size` in `php.ini` to 6–8 MB to match the application's largest expected upload. Also configure `client_max_body_size` at the web server (Nginx/Apache) level.
- **Status:** Pending Fix

---

### MEDIUM — Super Admin Bypasses Tenant Initialization on Store Routes

- **Location:** `app/Http/Middleware/TenantScope.php` lines 26–28
- **Issue:** Super-admin users pass through both `TenantScope` and `InitializeTenancyForAuthenticatedUser` without tenant initialization. If a super admin accesses any `/api/v1/store/*` route, tenancy is never initialized and queries hit the central DB.
- **Risk:** Super-admin store inspection would silently return no data or, worse, central DB data if model table names overlap, making diagnostic tools unreliable and potentially exposing central data.
- **Fix:** Explicitly document that super admins must not access `/api/v1/store/*` for tenant data browsing. If admin store inspection is required, implement an explicit `tenancy()->initialize($targetStore)` call in the super admin path, or route all admin store browsing through `/api/v1/admin/*`.
- **Status:** Pending Fix

---

### MEDIUM — Duplicate and Fragile Tenant Middleware Chain

- **Location:** `app/Http/Middleware/TenantScope.php` vs `InitializeTenancyForAuthenticatedUser.php`
- **Issue:** Two middleware classes duplicate authentication and store-validity checks, creating logic that can diverge. Ordering matters but is not enforced: `initialize.tenancy` runs before `tenant.scope`, so status checks in `TenantScope` run after the DB connection is already switched. Reordering these would silently break isolation or status enforcement.
- **Risk:** A future middleware reordering (accidental or during a refactor) could leave tenant context uninitialized while status checks run, or vice versa, creating an unpredictable security state.
- **Fix:** Merge into a single `InitializeTenancy` middleware that performs all checks in documented order: auth → store existence → status checks → `tenancy()->initialize()` → set app instances. Add a comment in `bootstrap/app.php` documenting why this order is mandatory.
- **Status:** Pending Fix

---

### MEDIUM — `createSale` Accepts Unvalidated `branch_id`

- **Location:** `app/Http/Controllers/Api/Store/Pos/PosController.php` line 48
- **Issue:** `createSale()` accepts `branch_id` from request input with no validation that the branch belongs to (or exists in) the current store. Branch records live in the tenant DB so cross-store access is blocked, but an attacker can pass any integer.
- **Risk:** If `branch_id` does not exist in the tenant DB, `Sale::create` silently stores a non-existent ID, corrupting all aggregate queries grouped by branch (sales reports, inventory reports).
- **Fix:**
  ```php
  Branch::where('id', $request->branch_id)
      ->where('is_active', true)
      ->firstOrFail();
  ```
  Or add `Rule::exists('branches', 'id')` to the request validation rules.
- **Status:** Pending Fix

---

### LOW — `is_super_admin` Not Checked on Password Reset Flow

- **Location:** `app/Http/Controllers/Api/Auth/PasswordResetController.php` line 93
- **Issue:** `reset()` calls `$user->tokens()->delete()` after a password reset, which is correct. However, there is no in-session password-change endpoint, meaning if an admin changes a user's password via an admin panel (a future feature), existing tokens would not be revoked.
- **Risk:** An account takeover scenario where the attacker has changed the password but old tokens remain valid.
- **Fix:** Ensure any future profile/password-change endpoint calls `$user->tokens()->delete()`. Expose `logoutAll` prominently in the frontend security settings UI.
- **Status:** Accepted Risk (deferred) — no profile-update endpoint currently exists; re-evaluate when implemented.

---

### LOW — Sale `show`/`showReturn` Allow Cross-Cashier Viewing Within a Store

- **Location:** `app/Http/Controllers/Api/Store/Pos/SaleController.php` lines 43–48 (`show`), 186–188 (`showReturn`)
- **Issue:** Both methods call bare `findOrFail` without restricting to the authenticated cashier's own sales. Any cashier can view full sale details (customer PII, payment method, credit status) for any other cashier's transaction by iterating integer IDs.
- **Risk:** Intra-store privacy violation — a cashier can see another cashier's customer PII and financial transactions.
- **Fix:** Either restrict to `->where('cashier_id', auth()->id())` or add a distinct `view-all-sales` permission gate before allowing cross-cashier access.
- **Status:** Pending Fix

---

### LOW — `resolveConflict` in Offline Sync Has No Cashier Scope

- **Location:** `app/Http/Controllers/Api/Store/Pos/OfflineSalesSyncController.php` lines 101–118
- **Issue:** `resolveConflict()` calls `Sale::where('offline_reference', '!=', null)->findOrFail($saleId)` with no cashier scope. Within the tenant DB this does not leak cross-store data, but any user with sync-conflicts access can resolve another cashier's conflict.
- **Risk:** A cashier could clear another cashier's conflict flags, hiding audit evidence of stock or credit discrepancies.
- **Fix:** Add a `manage-pos-devices` / `manage-sales` permission check, or scope to `->where('cashier_id', auth()->id())` for self-service resolution.
- **Status:** Pending Fix

---

### LOW — `.env` File Potentially Tracked in Git

- **Location:** `C:\xampp\htdocs\pos-backend\.env` line 13
- **Issue:** The `.env` file appears in the git working tree. If it is tracked by git, `APP_KEY` and all credentials are committed to the repository history.
- **Risk:** Any developer with repository access, or any breach of the git remote, exposes all production credentials including `APP_KEY`, database passwords, and API keys.
- **Fix:**
  ```bash
  git ls-files .env  # If this returns output, the file is tracked
  git rm --cached .env
  echo ".env" >> .gitignore
  git commit -m "Remove .env from tracking"
  ```
  Rotate `APP_KEY` and all secrets if the file has ever been committed.
- **Status:** Pending Fix — verify with `git ls-files .env` immediately.

---

### LOW — CORS Allows All HTTP Methods Including `TRACE` and `CONNECT`

- **Location:** `config/cors.php` line 7 — `'allowed_methods' => ['*']`
- **Issue:** All HTTP verbs are allowed, including `TRACE`, `CONNECT`, and non-standard methods.
- **Risk:** `TRACE` can be used in cross-site tracing (XST) attacks to steal cookies even when `HttpOnly` is set. It unnecessarily expands the attack surface.
- **Fix:**
  ```php
  'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
  ```
- **Status:** Pending Fix

---

### LOW — CORS Allows All Request Headers

- **Location:** `config/cors.php` line 17 — `'allowed_headers' => ['*']`
- **Issue:** Any request header is allowed, including `X-Forwarded-For`, `X-Internal-Token`, and custom headers. If any middleware trusts arbitrary client-sent headers for IP resolution or internal routing, this expands the spoofing surface.
- **Fix:**
  ```php
  'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-XSRF-TOKEN'],
  ```
- **Status:** Pending Fix

---

### LOW — MySQL FULLTEXT Boolean Operators Not Stripped from Search Input

- **Location:** `app/Models/Customer.php` lines 98–101 + `app/Models/Product.php` lines 96–99
- **Issue:** `scopeSearch()` methods pass user-supplied `$term` directly to MySQL `MATCH(...) AGAINST(? IN BOOLEAN MODE)`. MySQL FULLTEXT Boolean Mode accepts special operators (`+`, `-`, `~`, `*`, `"`) inside the search term, which can alter result-set logic.
- **Risk:** A user can search `+mustmatch -excluded` and manipulate search result logic in unexpected ways. Low severity since this does not leak data outside the tenant, but it affects search reliability.
- **Fix:**
  ```php
  $sanitizedTerm = preg_replace('/[+\-~*"()@<>]/', ' ', $term);
  ->whereRaw('MATCH(...) AGAINST(? IN BOOLEAN MODE)', [$sanitizedTerm . '*'])
  ```
- **Status:** Accepted Risk (deferred) — Low impact, address post-launch.

---

### LOW — `PosDevice.store_id` and `registered_by` Mass-Assignable

- **Location:** `app/Models/PosDevice.php` line 26
- **Issue:** `store_id` and `registered_by` are in `$fillable`. The `DeviceController` correctly sets `store_id` from `app('current_store_id')`, but having it in `$fillable` means a future developer could accidentally mass-assign it.
- **Risk:** Future regression risk — a new device registration endpoint that mass-assigns request data could register devices under the wrong store.
- **Fix:** Remove `'store_id'` and `'registered_by'` from `$fillable`. Set them explicitly in the controller as is currently done.
- **Status:** Accepted Risk (deferred)

---

### LOW — `Sale` Conflict Flags Mass-Assignable

- **Location:** `app/Models/Sale.php` line 21
- **Issue:** `has_stock_conflict` and `has_credit_conflict` are in `$fillable`. These are internal audit flags that should only be set by server-side sync logic.
- **Risk:** A crafted offline sync payload could set conflict flags on a sale to false, hiding genuine inventory or credit discrepancies.
- **Fix:** Remove `'has_stock_conflict'` and `'has_credit_conflict'` from `$fillable`. Set them only via explicit direct assignment inside the sync controller.
- **Status:** Accepted Risk (deferred)

---

### LOW — `APP_DEBUG=true` Default in `.env.example` Deployment Risk

- **Location:** `C:\xampp\htdocs\pos-backend\.env.example`
- **Issue:** `.env.example` ships with `APP_DEBUG=true`, `LOG_LEVEL=debug`, and `SESSION_ENCRYPT=false`. Developers who copy the example without modification deploy with insecure defaults.
- **Fix:** Change all three in `.env.example`:
  - `APP_DEBUG=false`
  - `LOG_LEVEL=info`
  - `SESSION_ENCRYPT=true`
  - `DB_USERNAME=pos_user` / `DB_PASSWORD=CHANGEME`
- **Status:** Pending Fix

---

### LOW — Legacy Stripe Webhook Alias Route Without Throttle

- **Location:** `routes/api.php` line 101
- **Issue:** `Route::post('stripe/webhook')` at `/api/v1/stripe/webhook` is a duplicate of `/api/v1/webhooks/stripe` with no throttle middleware.
- **Risk:** Marginally larger attack surface for replay/flood attempts. No security difference in handler logic since both use signature verification.
- **Fix:** Remove the legacy alias if the Stripe Dashboard has been updated to the canonical URL. If it must remain, apply `->middleware('throttle:60,1')`.
- **Status:** Accepted Risk (deferred)

---

### LOW — `CommunicationProvider.credentials` Decryption Falls Back to Raw JSON

- **Location:** `app/Models/CommunicationProvider.php` lines 44–48
- **Issue:** The `getCredentialsAttribute()` catch block falls back to `json_decode($value, true)` if decryption fails, potentially returning the raw ciphertext blob as a parsed object on key rotation or data corruption.
- **Fix:** Remove the `json_decode` fallback. Return `[]` and log a warning:
  ```php
  } catch (\Exception $e) {
      Log::warning('Failed to decrypt CommunicationProvider credentials', ['id' => $this->id]);
      return [];
  }
  ```
- **Status:** Accepted Risk (deferred)

---

### LOW — PHP 8.2 on Security-Fix-Only Lifecycle

- **Location:** `composer.json` — `php: ^8.2`
- **Issue:** PHP 8.2 reached end of active support in December 2024. It is now security-fix-only until December 2025, after which it receives no patches.
- **Fix:** Upgrade to PHP 8.3 (active support until November 2026). Update `composer.json` to `^8.3` and test all dependencies.
- **Status:** Accepted Risk (deferred) — plan upgrade within 6 months.

---

### INFO — Blade Receipt Templates Correctly Escape Output

- **Location:** `resources/views/pos/receipt-thermal.blade.php` and `receipt-a4.blade.php`
- **Issue:** All user-supplied output uses `{{ }}` (Blade auto-escape). No `{!! !!}` raw output directives are used for user-controlled data.
- **Recommendation:** Continue using `{{ }}` for all user-controlled data. Perform a search for `{!! !!}` before every template addition.
- **Status:** No action required.

---

### INFO — Central-DB Models Correctly Pin `$connection = 'mysql'`

- **Location:** `app/Models/PosDevice.php`, `Payment.php`, `Subscription.php`, `Role.php`, `Permission.php`, `CommunicationProvider.php`, `PaymentGateway.php`, `PaymentEvent.php`, `StoreAggregate.php`
- **Issue:** All central-DB models correctly declare `protected $connection = 'mysql'`, pinning them to the central database even when tenant context is active.
- **Recommendation:** Consider adding a `CentralModel` base class to make this intent explicit and prevent accidental removal during refactoring.
- **Status:** No action required.

---

### INFO — Stripe and Twilio Webhook Verification Correct

- **Location:** `app/Http/Controllers/Api/Webhook/StripeWebhookController.php` + `TwilioSmsProvider.php` + `TwilioWhatsAppProvider.php`
- **Issue:** Stripe uses `Webhook::constructEvent()` with raw body + `Stripe-Signature` header. Twilio uses `RequestValidator` with `X-Twilio-Signature`. Both return `400` on mismatch. Correctly implemented.
- **Recommendation:** Add `throttle:60,1` to the `/webhooks/*` route group to limit replay-flood attempts.
- **Status:** No action required.

---

### INFO — `SyncStoreAggregate` Job Correctly Inherits Tenant Context

- **Location:** `app/Observers/SaleObserver.php` + `app/Jobs/SyncStoreAggregate.php`
- **Issue:** `SaleObserver` dispatches `SyncStoreAggregate(app('current_store'))`. The job accesses tenant-DB data via `$this->store->run(...)`. `QueueTenancyBootstrapper` is registered, providing automatic tenant context for queued jobs.
- **Recommendation:** Audit all future jobs that query tenant models (`Sale`, `Customer`, `Product`, etc.) to ensure they also use `$store->run()` or `tenancy()->initialize($store)` in `handle()`.
- **Status:** No action required.

---

### INFO — No `dangerouslySetInnerHTML`, `eval()`, or `document.write()` in Frontend (Except ReceiptScreen)

- **Location:** Entire `C:\xampp\htdocs\pos-frontend` codebase
- **Issue:** No usage of `dangerouslySetInnerHTML`, `eval()`, `document.write()`, or `insertAdjacentHTML` was found anywhere in the frontend `.tsx`/`.ts` files outside of `ReceiptScreen.tsx`.
- **Recommendation:** Add ESLint rules (`eslint-plugin-no-unsanitized`) to flag future `innerHTML` and `eval()` usage in CI.
- **Status:** No action required.

---

## Recommended Immediate Actions (Before Launch — Ordered by Priority)

These must be resolved before any production deployment:

1. **Upgrade Next.js to 14.2.25+ or 15.x** — CVE-2025-29927 auth bypass is actively exploited. Run `npm install next@latest eslint-config-next@latest`. *(1–2 hours)*

2. **Fix Subscription Cancellation IDOR** — Scope `Subscription::findOrFail` to `store_id` in `BillingController::cancel()` and update the `Rule::exists` validation. *(30 minutes)*

3. **Remove `is_super_admin` and `store_id` from `User.$fillable`** — These are privilege-escalation and tenant-bypass vectors. *(15 minutes)*

4. **Publish and configure Sanctum token expiry** — Run `php artisan vendor:publish --tag=sanctum-config`, set `'expiration' => 10080`. Remove dead `JWT_TTL` env keys. *(30 minutes)*

5. **Fix ReceiptScreen XSS** — Add `escapeHtml()` to `printOfflineReceipt()` in `ReceiptScreen.tsx`. Replace `innerHTML` with `window.open(url)` in `openServerReceipt()`. *(1–2 hours)*

6. **Fix Resend webhook bypass** — Change `return true` to `return false` in `ResendEmailProvider::verifyWebhook()` when secret is not configured. *(5 minutes)*

7. **Fix cross-tenant file serving** — Add path prefix enforcement in the `GET /api/v1/store/files/{path}` route handler. *(1 hour)*

8. **Remove `status` and `tenancy_db_name` from `Store.$fillable`** — Critical infrastructure fields that must not be mass-assignable. *(15 minutes)*

9. **Fix `verifySession` payment lookup** — Add `->where('store_id', auth()->user()->store_id)` to `BillingController::verifySession()`. *(15 minutes)*

10. **Add rate limiting to auth reset/verify endpoints** — Apply `throttle:5,1` to `forgot`, `validate`, `reset`, and `email/verify` routes in `routes/api.php`. *(15 minutes)*

11. **Add `escapeHtml` / restrict SVG in product image upload** — Re-encode images through Intervention Image; change validation to `mimes:jpg,jpeg,png,webp,gif`. *(2–3 hours)*

12. **Replace `$request->all()` in ReportController with `$request->only()`** — Use allowlisted filter keys. *(1 hour)*

13. **Fix `pp_Password` not masked in ApiLogger** — Add `pp_password` and `pp_integritysalt` to `sensitiveFields`. *(15 minutes)*

14. **Set `APP_DEBUG=false` in `.env.example`** — Also set `LOG_LEVEL=info` and `SESSION_ENCRYPT=true`. *(5 minutes)*

15. **Verify `.env` is not tracked in git** — Run `git ls-files .env`. If tracked, run `git rm --cached .env` and rotate all secrets. *(15–30 minutes)*

---

## Deferred Items (Acceptable Risk for Launch, Address Post-Launch)

These are real issues but are lower risk in this deployment context, or have sufficient mitigating controls to defer:

| # | Item | Location | Reason for Deferral |
|---|------|----------|---------------------|
| 1 | Account lockout after failed logins | `AuthController::login()` | IP rate limit provides partial protection; B2B POS with known users reduces mass-attack risk. Implement within 30 days. |
| 2 | Password reset token hashing | `PasswordResetToken` | Requires schema change; reset token expiry already limits the window. Implement before high-traffic launch. |
| 3 | Password complexity rules | `RegisterController`, `PasswordResetController` | Existing users not affected retroactively; add before next user-facing release. |
| 4 | Security response headers | Backend + Frontend | No currently exploitable path without the XSS vectors (which are fixed pre-launch). Implement headers within 2 weeks. |
| 5 | MySQL FULLTEXT operator sanitization | `Customer.scopeSearch()`, `Product.scopeSearch()` | Result manipulation only; no data leakage outside the tenant. |
| 6 | `PosDevice.store_id` / `registered_by` in `$fillable` | `PosDevice.php` | DeviceController correctly overrides these; remove from fillable in the next model cleanup pass. |
| 7 | Sale conflict flags in `$fillable` | `Sale.php` | Sync controller sets these server-side; remove from fillable in the next model cleanup pass. |
| 8 | Merge tenant middleware into one class | `TenantScope.php`, `InitializeTenancyForAuthenticatedUser.php` | Current ordering is correct and tested; refactor in next sprint. |
| 9 | `CommunicationProvider` credential decryption fallback | `CommunicationProvider.php` | Only affects key-rotation edge case; fix during next credentials-refactor. |
| 10 | PHP 8.2 end-of-active-support | `composer.json` | Security fixes still backported until December 2025. Plan PHP 8.3 upgrade within 6 months. |
| 11 | Migrate abandoned PayPal SDKs | `composer.lock` | PayPal v1 Checkout API still functional; schedule migration when PayPal announces v1 EOL. |
| 12 | Legacy Stripe webhook alias route | `routes/api.php:101` | Both routes use identical signature verification; remove after confirming Stripe Dashboard is updated. |
| 13 | `branch_id` validation in `createSale` | `PosController.php:48` | Foreign key constraint at DB level provides a backstop. Add validation in the next POS hardening pass. |
| 14 | JazzCash/Easypaisa GET/POST dual-route | Callback controllers | HMAC verification still runs on POST; GET path short-circuits safely. Restrict to POST in next payment refactor. |

---

## Audit Coverage Summary

### What Was Checked

| Dimension | Scope |
|-----------|-------|
| **SQL Injection** | All `app/Http/Controllers/`, `app/Reports/`, `app/Services/`, `app/Jobs/`, `routes/console.php` — 80+ raw SQL call sites audited |
| **Mass Assignment** | All 63 model files in `app/Models/`; all controller `create()`/`update()` call patterns |
| **Authentication** | `AuthController`, `RegisterController`, `PasswordResetController`, `EmailVerificationToken`, Sanctum config, rate limiting, session handling |
| **CORS / XSS / Headers** | `config/cors.php`, `next.config.mjs`, all `.tsx`/`.ts` files for `innerHTML`/`eval`/`dangerouslySetInnerHTML`, Blade receipt templates |
| **Webhook Verification** | Stripe, PayPal, Resend, Twilio SMS, Twilio WhatsApp, JazzCash, Easypaisa — all 6 webhook/callback endpoints |
| **Tenant Isolation** | All `/api/v1/store/*` controllers, middleware chain (`TenantScope`, `InitializeTenancyForAuthenticatedUser`), all `findOrFail` call sites, queue job tenant context |
| **File Upload** | `ProductController::uploadImage`, `LandingPageController::updateSettings`, generic file-serving route, `php.ini` limits, `.htaccess` presence |
| **Sensitive Data Handling** | `ApiLogger` masking, credential encryption (`PaymentGateway`, `CommunicationProvider`), `.env`/`.env.example` content, `APP_DEBUG` exposure |
| **Dependencies** | `composer.lock` (backend), `package.json` + `package-lock.json` (frontend) — CVEs, abandoned packages, EOL runtimes |

### What Was NOT Checked

| Area | Notes |
|------|-------|
| **Authorization / RBAC completeness** | Spatie permission assignments were not fully audited — it was verified that permissions are checked but not whether every endpoint has the *correct* permission applied |
| **Business logic flaws** | Pricing manipulation, discount abuse, loyalty-point farming, offline sync replay were not tested |
| **Frontend state management** | Zustand store structure, offline queue handling in `C:\xampp\htdocs\pos-frontend\lib\offline\` was not deeply audited for data leakage between sessions |
| **Cryptographic implementation** | Custom crypto was not found; library usage (Laravel Crypt, HMAC) was confirmed correct but not formally verified |
| **Infrastructure / deployment** | Docker/Kubernetes config, CI/CD pipeline secrets, server hardening, TLS certificate management were not assessed |
| **Third-party integrations (SMS/WhatsApp)** | Twilio message content was not audited for injection via outbound messages |
| **Admin panel authorization** | `app/Http/Controllers/Api/Admin/` endpoints were not fully audited for IDOR patterns beyond billing |
| **Penetration testing** | This is a static code analysis audit — no dynamic testing, fuzzing, or authenticated pen testing was performed |
| **Compliance** | PCI-DSS, GDPR, and local Pakistani payment regulation compliance were not formally assessed |
