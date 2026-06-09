# Phase 6 — PWA + Offline POS

## Overview

The POS screen (`/dashboard/pos`) works fully offline. Cashiers can scan products, build a cart, take payment, and print receipts without internet. Sales are saved locally and sync automatically when the connection returns.

**All other screens** (inventory, reports, customers, settings) require internet and show an offline block screen.

---

## What Works Offline

| Feature                        | Offline | Notes                                           |
|-------------------------------|---------|------------------------------------------------|
| Product search & barcode scan | ✅       | From local IndexedDB cache                     |
| Customer lookup               | ✅       | From local cache (top 500 by recent purchase)  |
| Cart — add/remove items       | ✅       | Local cart, no server calls                    |
| Cash / card / on-credit       | ✅       | Cashier trusts — server reconciles on sync     |
| Loyalty point redemption      | ✅       | Uses cached balance                            |
| JazzCash / Easypaisa          | ❌       | Requires gateway redirect                      |
| Creating new customers        | ❌       | Too conflict-prone                             |
| Returns / refunds             | ❌       | Original sale may not be cached                |
| Reports, inventory, settings  | ❌       | Full-page offline block shown                  |

---

## How It Works

### 1. Device Registration

When the POS screen first loads, the browser generates a unique device UUID stored in IndexedDB. The device is registered with the server (`POST /store/pos/devices/register`) to create an audit trail.

Each device's ID prefix is used for offline sale numbers: `OFF-{LAST_6_UUID}-{SEQUENCE}`.

### 2. Data Sync (Server → Device)

On first load, the POS downloads all products, customers, and reference data to IndexedDB. This "initial sync" shows a progress overlay and takes ~2–10 seconds depending on catalog size.

After the initial sync, the POS updates automatically:
- **Every 5 minutes** — incremental update for changed products/customers
- **Immediately on reconnect** — after a connection drop

### 3. Offline Sale Flow

1. Cashier selects products (from IndexedDB cache)
2. Optionally attaches a cached customer
3. Completes payment — blocked methods (JazzCash, Easypaisa) are hidden
4. Sale saved to IndexedDB `pending_sales` with status `pending_sync`
5. Receipt printed immediately with `OFF-XXXXXX-000001` reference

### 4. Sync (Device → Server)

Pending sales are uploaded to the server automatically:
- Every **30 seconds** when online
- **Immediately** on reconnect

The server assigns a real sale number (`S-2026-00000042`), decrements stock, applies loyalty, and records `offline_reference` for audit.

### Conflict Handling

The server **never rejects** an offline sale — the cashier already physically completed the transaction. Instead:

- **Stock conflict** — item oversold offline → sale flagged, visible at `/dashboard/pos/sync-conflicts`
- **Credit conflict** — customer credit limit exceeded offline → sale flagged
- **Product deleted** — server still records the sale, item name preserved
- **Customer deleted** — sale recorded, customer link removed

Review and resolve conflicts at **Dashboard → Sync Conflicts**.

---

## Installation on Android / iOS

### Android (Chrome)

1. Open `https://your-pos-domain.com/dashboard/pos` in Chrome
2. Tap the ⋮ menu → **Add to home screen** (or wait for the install prompt)
3. Tap **Install**
4. The app opens in standalone mode (no browser chrome)

### iOS (Safari 15+)

1. Open the URL in Safari
2. Tap the **Share** button (square with arrow) → **Add to Home Screen**
3. Tap **Add**

> **Note:** iOS Safari has limited Background Sync support. Sales will upload when you next open the app and connect.

### Desktop (Chrome / Edge)

Look for the install icon (⊕) in the address bar, or go to ⋮ menu → **Install POS System**.

---

## Deactivating a Lost or Stolen Device

1. Go to **Settings → POS Devices**
2. Find the device and click **Deactivate** (trash icon)
3. Confirm the deactivation

The deactivated device will receive a **403 Forbidden** on its next sync attempt and cannot upload any more offline sales. Any pending (not yet uploaded) sales on that device are lost.

---

## Browser Compatibility

| Browser       | Offline POS | Install as PWA | Background Sync |
|---------------|-------------|----------------|-----------------|
| Chrome 90+    | ✅ Full      | ✅              | ✅               |
| Edge 90+      | ✅ Full      | ✅              | ✅               |
| Safari 15+    | ✅ Full      | ✅ (via Share)  | ⚠️ Limited       |
| Firefox       | ⚠️ Partial  | ❌              | ❌               |
| Samsung Browser 14+ | ✅ Full | ✅           | ✅               |

---

## Recovering from Errors

### "POS not loading correctly offline"

1. Go to **Settings → POS Configuration → Offline Mode**
2. Scroll to **Danger Zone** → click **Reset All Offline Data on This Device**
3. Confirm — this clears the cache and any pending unsynced sales
4. Reopen the POS — it will re-download everything

> ⚠️ **Only reset if you are sure all pending sales have been uploaded first.** Check **Settings → POS Devices** to see the pending count.

### Sale shows as "Pending sync" but won't upload

1. Check your internet connection
2. Open the POS screen — upload tries automatically every 30 seconds
3. Click the sync indicator pill (top-right of POS) → **Upload pending sales**
4. If the device is deactivated, it will show 403 — contact your admin

### Sync conflict appears

1. Go to **Dashboard → Sync Conflicts**
2. Review each conflict — the sale is already recorded on the server
3. For stock conflicts: manually adjust inventory from **Inventory** screen
4. For credit conflicts: contact the customer to arrange payment
5. Click **Acknowledge** to remove from the list

---

## Edge Cases Reference

| Case | Behavior |
|------|----------|
| A. Goes offline mid-cart | Cart preserved in IndexedDB; can complete offline |
| B. Browser closed with pending sale | Sale remains in pending_sales on reopen; syncs when online |
| C. Same product sold offline + online simultaneously | Both sales recorded; offline sale flagged with stock conflict |
| D. Credit limit exceeded offline | Sale recorded; flagged for review; customer balance updated server-side |
| E. Product deleted while device offline | Sale syncs; item name preserved in sale record; server logs warning |
| F. Tax rate changed while offline | Cached rate used; server reconciles on sync |
| G. Loyalty rules changed while offline | Server applies current rules on sync; points adjusted if needed |
| H. Customer deleted while device offline | Sale syncs; customer relation cleared with note |
| I. Device clock wrong | Server logs time skew; accepts sale with server timestamp |
| J. App update available | Blue banner on POS: "Update now" button reloads the app |
| K. IndexedDB corrupted | Reset via Settings → POS → Offline Mode → Reset All Offline Data |
| L. Storage quota exceeded | Warning shown in Settings → POS → Offline Mode; reduce cache limits |
