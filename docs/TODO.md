# Phase 6 — PWA + Offline POS — Known Issues & Rough Edges

This file documents rough edges discovered during final verification (Step 9).
Items are prioritised: **P1** = blocks cashier workflow, **P2** = degraded UX, **P3** = cosmetic/polish.

---

## Active Issues

### P2 — Loyalty point redemption disabled offline
**Symptom:** The "Redeem Loyalty Points" modal shows "Requires internet" on the Apply button when offline.  
**Why:** The modal calls the server to deduct points, which requires knowing the current server-side balance.  
**Workaround:** Use the **"Loyalty Points"** payment method in the PaymentModal instead — this is fully supported offline.  
**Fix:** Add an offline path that applies a `loyalty_points` payment type using the cached balance, with server reconciliation on sync.

### P2 — Held (parked) sales not available offline
**Symptom:** The "Hold" button calls the server API. If offline and you try to hold a sale, it fails silently.  
**Why:** Hold/resume functionality was not included in offline scope (Phase 6 spec scoped to completing sales, not holding).  
**Workaround:** Complete or void the current sale before going offline. Do not park sales when about to go offline.  
**Fix:** Add IndexedDB-backed hold queue (separate from pending_sales).

### P2 — QZ Tray cash drawer does not auto-open after offline sale
**Symptom:** After completing an offline sale, the cash drawer does not open automatically.  
**Why:** The current drawer open implementation (`POST /store/pos/drawer/open`) makes a server API call. This was not connected to the offline completion path.  
**Workaround:** Cashier opens the drawer manually (physical button or QZ Tray web interface).  
**Fix:** Add QZ Tray direct WebSocket command in `completeOfflineSale()` — does not require server.

### P2 — Stock indicator on product cards may show stale values immediately after an offline sale
**Symptom:** After completing an offline sale, the product card still shows the pre-sale stock count for a brief moment.  
**Why:** `decrementCachedStock()` in `offline-sale.ts` runs asynchronously after the sale completes. The `useLiveQuery` reactive hook picks it up within ~100ms.  
**Impact:** Very brief (< 1 second). Normal behaviour — no user action needed.

### P3 — Offline sale number shows in receipt before sync
**Symptom:** Thermal receipts print with `OFF-XXXXXX-000001` format. After sync, this becomes `S-2026-00000042` on the server but the original receipt still shows the offline number.  
**Why:** Receipts are printed client-side at the time of sale. Re-printing after sync (from the server receipt endpoint) will show the real number.  
**Fix:** After successful sync, update `pending_sales.real_sale_number` (already done). Add UI prompt: "This sale has synced. Reprint with real number: S-2026-00000042?"

### P3 — PWA icons are placeholder solid-colour squares (non-maskable)
**Symptom:** The installed PWA icon on Android home screen is a plain indigo square, not the detailed logo.  
**Why:** Real PNG icons were generated as minimal 192×192 and 512×512 solid-colour placeholders (Step 1).  
**Fix:** Generate proper branded icons with the Sparkles/bolt logo at correct sizes. Use a tool like `sharp` or `pwa-asset-generator`.

### P3 — Firefox: Service Worker registers but Background Sync is not supported
**Symptom:** On Firefox, pending offline sales do not auto-upload in the background when the app is closed.  
**Why:** Firefox does not implement the Background Sync API. Uploads only happen when the POS screen is open.  
**Impact:** Sales still upload as soon as the user opens the POS. Data is never lost.  
**Workaround:** Recommend Chrome/Edge for tablets used as POS terminals.

### P3 — Safari iOS: install prompt is not automatic
**Symptom:** The install banner (`beforeinstallprompt`) does not fire on iOS Safari.  
**Why:** iOS Safari does not support the `beforeinstallprompt` event. Install must be done via the Share menu.  
**Fix:** On iOS, show a manual "How to install" instructional banner (detect iOS via `navigator.platform` or `userAgent`).

---

## Planned Improvements (Post-Phase-6)

- **Multi-device sync conflict UI** — When two devices sell the same last item offline simultaneously, the conflict surface shows the flagged sale. Consider showing BOTH sales side-by-side for easier reconciliation.

- **Offline sale search in history** — `/dashboard/pos` sale history shows only server-synced sales. Show pending local sales inline.

- **Background sync via Service Worker Background Sync API** — Currently the upload loop runs every 30s while the POS tab is open. Use the SW Background Sync API to upload even when the browser tab is closed (Chrome/Edge only).

- **Offline receipt template customization** — The offline receipt (printed before sync) uses a simple hardcoded template. It should use the store's configured thermal receipt template from `store_meta`.

- **Device-to-device clock sync warning** — If two devices disagree on time by > 30 minutes, flag it during registration.

---

## Verification Test Results (Step 9)

Run the following checklist on a physical device:

- [ ] 1. Fresh device: registration completes, UUID in IndexedDB
- [ ] 2. Full sync: products + customers visible in DevTools → Application → IndexedDB
- [ ] 3. PWA install prompt appears (Chrome)
- [ ] 4. Installed app opens in standalone mode (no browser bar)
- [ ] 5. Network disabled: product search instant from IndexedDB
- [ ] 6. Add 5 items to cart while offline
- [ ] 7. Attach cached customer
- [ ] 8. Apply 10% discount offline (routes to `offlineCartHook.setDiscount`)
- [ ] 9. Cash payment → "Complete Offline Sale" → receipt screen shows `OFF-*` reference
- [ ] 10. Sale visible in IndexedDB `pending_sales` with `status: 'pending_sync'`
- [ ] 11. Network restored: SyncIndicator changes to "Uploading N sales"
- [ ] 12. After sync: `pending_sales.status = 'synced'`, `real_sale_number` populated
- [ ] 13. Server DB: `SELECT sale_number, offline_reference, synced_at FROM sales WHERE offline_reference IS NOT NULL`
- [ ] 14. Credit sale offline: `on_credit` appears in PaymentModal, sale flags credit if limit exceeded
- [ ] 15. Loyalty payment offline: `loyalty_points` method in PaymentModal works
- [ ] 16. Stock conflict: sell last unit offline → online → sync → `/dashboard/pos/sync-conflicts` shows it
- [ ] 17. Resolve conflict → removed from list, sale preserved
- [ ] 18. Power off with pending sale → power on → sync resumes automatically
- [ ] 19. Deactivate device → subsequent sync returns 403 → device marked deactivated
