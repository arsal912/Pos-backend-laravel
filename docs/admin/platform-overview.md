# Platform Admin Overview

You are the super admin — you have access to everything.

## Your Admin Panel
URL: admin.{DOMAIN}
Login: admin@possystem.com / (your password)

## What You Can Do

### Stores Management (/admin/stores)
- See all registered stores
- View each store's revenue, customer count, subscription status
- Suspend / reactivate stores
- Impersonate a store owner (access their dashboard as them)
- Delete stores (soft-delete with 30-day recovery)

### Subscriptions (/admin/subscriptions)
- See all active, trial, expired subscriptions
- Extend trial periods
- Manually cancel or reactivate
- Grant free months

### Modules (/admin/modules)
- Toggle features per store
- Override plan defaults per user
- Example: enable "advanced reports" for a store on Basic plan

### Payment Gateways (/admin/payment-gateways)
- Configure Stripe, PayPal, JazzCash, Easypaisa credentials
- These apply to all stores (platform-level integration)

### Communications (/admin/communications)
- View platform-wide message volume and costs
- Configure SMS/email/WhatsApp providers (Twilio, Resend)

### POS Devices (/admin/pos-devices)
- See all registered POS terminals across all stores
- Deactivate lost/stolen devices
- See pending offline sales per device

### API Logs (/admin/api-logs)
- Every API request is logged with user, endpoint, status, duration
- Use for debugging customer issues
- Purge logs older than 30 days

## Common Admin Tasks

### New Customer Onboarding
1. Customer registers at {DOMAIN}/register
2. Their store appears in /admin/stores with status "active" (trial)
3. Contact them via their store email to welcome them
4. Check /admin/api-logs for any registration errors

### Investigating a Customer Issue
1. Go to /admin/stores → find the store
2. Click "Analytics" to see recent activity
3. Go to /admin/api-logs → filter by store_id
4. Use "Impersonate" to log in as the store owner and reproduce the issue

### Emergency: Deactivate a Compromised Store
1. /admin/stores → find store → "Update Status" → Suspended
2. This immediately blocks all API access for that store's users
3. Document the reason in the notes field
