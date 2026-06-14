# Troubleshooting Common Issues

## POS Issues

### "Module not enabled" error
**Cause:** The feature you're trying to use isn't activated for your plan.
**Fix:** Contact support or your platform administrator to enable the module.

### Products not showing
**Cause:** Products may be inactive or out of stock (if "out of stock" filter is on).
**Fix:** Go to Products and ensure "Active" toggle is ON. Check stock levels in Inventory.

### Barcode not scanning
**Cause:** Barcode scanner needs to be in keyboard mode (HID mode).
**Fix:** Check your scanner manual. It should send keystrokes ending in Enter/Return.

### Sale shows "Offline - will sync" on receipt
**Cause:** The POS completed the sale while internet was unavailable.
**Fix:** Normal behavior — the sale is saved and will sync automatically when connected. No action needed.

### Cash drawer not opening
**Cause:** QZ Tray may not be running.
**Fix:** Download and start QZ Tray at https://qz.io. Ensure it's configured for your printer/drawer.

## Reports Issues

### "Report failed" error
**Cause:** Usually a date range with no data or a database connection issue.
**Fix:** Try a different date range. If it persists, contact support.

### Customer stats showing zero
**Cause:** Stats are updated when sales complete. New customers show zero until their first sale.
**Fix:** Normal — no action needed.

## Inventory Issues

### Stock showing wrong quantity
**Fix:** Go to Inventory → click Adjust on the product → enter the correct quantity → Submit & Apply.

### "Insufficient stock" at POS
**Cause:** Product has track_stock ON and quantity is 0.
**Fix:** Either receive new stock (via GRN) or adjust quantity in Inventory.
