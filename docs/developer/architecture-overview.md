# Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                         CLIENT LAYER                            │
│                                                                 │
│  Browser / PWA         Admin Browser        Marketing Site      │
│  app.{DOMAIN}          admin.{DOMAIN}       {DOMAIN}           │
│  (POS Dashboard)       (Super Admin)        (Landing Page)     │
└──────────┬──────────────────┬───────────────────┬──────────────┘
           │                  │                   │
           ▼                  ▼                   ▼
┌─────────────────────────────────────────────────────────────────┐
│                      CDN / NGINX LAYER                          │
│  - SSL termination (Let's Encrypt)                              │
│  - Security headers (HSTS, X-Frame-Options, CSP)               │
│  - Rate limiting (60 req/min per IP on API)                     │
│  - Static asset caching (.next/static → 365d)                  │
└──────────┬──────────────────┬───────────────────────────────────┘
           │                  │
           ▼                  ▼
┌────────────────────┐  ┌─────────────────────────────────────────┐
│   Next.js 14       │  │         Laravel 11 API                  │
│   (Frontend)       │  │         api.{DOMAIN}                    │
│                    │  │                                         │
│  - SSR + PWA       │  │  Route groups:                          │
│  - Offline mode    │  │  /api/v1/auth/*    (public)             │
│  - IndexedDB cache │  │  /api/v1/store/*   (tenant, sanctum)   │
│  - Service Worker  │  │  /api/v1/admin/*   (super-admin)        │
│                    │  │  /api/v1/webhooks/* (signature-verified)│
└────────────────────┘  └──────────┬──────────────────────────────┘
                                   │
           ┌───────────────────────┴────────────────────────┐
           │                                                │
           ▼                                                ▼
┌─────────────────────┐                        ┌──────────────────────┐
│  MySQL: pos_system  │                        │ MySQL: pos_store_N   │
│  (Central DB)       │                        │ (Per-Tenant DB)      │
│                     │                        │                      │
│  - users            │  stancl/tenancy v3     │  - products          │
│  - stores           │  DatabaseTenancy       │  - customers         │
│  - plans            │  Bootstrapper          │  - sales             │
│  - modules          │  ─────────────────►   │  - inventory_items   │
│  - subscriptions    │                        │  - loyalty_txns      │
│  - permissions      │                        │  - communication_logs│
│  - pos_devices      │                        │  - campaigns         │
│  - api_loggings     │                        │  - pending_sales     │
└─────────────────────┘                        └──────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────┐
│                      QUEUE WORKERS                              │
│  Supervisor manages 3 workers:                                  │
│  - pos-queue (2 workers): main queue — all standard jobs        │
│  - pos-queue-comms (1 worker): communications queue             │
│                                                                 │
│  Key async jobs:                                                │
│  - SyncStoreAggregate (dashboard metrics)                       │
│  - SendSmsJob / SendEmailJob / SendWhatsAppJob                  │
│  - DispatchCampaignJob (bulk messaging)                         │
│  - OfflineSalesSyncProcessor                                    │
└─────────────────────────────────────────────────────────────────┘
           │
           ▼
┌─────────────────────────────────────────────────────────────────┐
│                   EXTERNAL SERVICES                             │
│                                                                 │
│  Payments:     Stripe, PayPal, JazzCash, Easypaisa             │
│  Email:        Resend (transactional + marketing)              │
│  SMS:          Twilio                                           │
│  WhatsApp:     Twilio WhatsApp Business                        │
│  Error Track:  Sentry (backend + frontend)                     │
│  Backups:      S3-compatible (DigitalOcean Spaces / B2)        │
└─────────────────────────────────────────────────────────────────┘
```

## Key Architectural Decisions

### Multi-Tenancy: Database-per-Tenant
Each store gets its own MySQL database (pos_store_N). This provides:
- Complete data isolation (no shared tables)
- Easy per-tenant backup/restore
- No tenant ID filtering bugs
- Scale: can move individual tenants to separate DB servers

Central database (pos_system) holds: users, stores, plans, modules, billing, permissions.

### Auth: Laravel Sanctum
Token-based auth. Tokens stored in browser localStorage (documented risk, acceptable for POS context).
Token expiry: 7 days. Refresh: re-login.
Admin users use is_super_admin flag on User model.

### Offline-First POS
Phase 6 implemented full offline capability:
- Dexie.js for IndexedDB storage
- Service Worker (via @ducanh2912/next-pwa)
- Offline cart with IndexedDB persistence
- Background sync (POST /store/pos/sync/sales) when reconnected
- Conflict detection (stock/credit) with manual resolution UI
