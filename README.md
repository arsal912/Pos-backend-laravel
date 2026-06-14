# POS System — Multi-Tenant SaaS

A full-featured, production-ready Point of Sale SaaS platform built for the Pakistani retail market.
Works fully offline on tablets. Supports multiple tenants with complete data isolation.

## Features
- 🖥️ **Full POS screen** with barcode scanning, cart, discounts, receipts
- 📦 **Inventory management** with multi-branch stock tracking
- 👥 **CRM** with loyalty points, customer groups, credit management
- 💬 **Communications** — SMS, Email, WhatsApp campaigns
- 📊 **15+ Reports** — sales, inventory, customers, finance
- ✈️ **Offline-first PWA** — complete sales without internet, auto-sync on reconnect
- 🏢 **Multi-tenant** — complete database isolation per store
- 💳 **Payments** — Stripe, PayPal, JazzCash, Easypaisa

## Tech Stack
| Layer | Technology |
|-------|-----------|
| Backend | Laravel 11, PHP 8.2 |
| Frontend | Next.js 14, TypeScript, TailwindCSS |
| Database | MySQL 8 (central + per-tenant) |
| Auth | Laravel Sanctum |
| Multi-tenancy | stancl/tenancy v3 |
| Offline | Dexie.js + Service Worker |
| Queue | Laravel Queue (database driver) |

## Quick Start (Development)

```bash
# Backend
cd pos-backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan db:seed
php artisan serve

# Frontend
cd pos-frontend
npm install
cp .env.example .env.local  # set NEXT_PUBLIC_API_URL=http://localhost:8000/api/v1
npm run dev
```

**Default login:** admin@possystem.com / password

## Documentation
| Docs | Link |
|------|------|
| Customer guide | [docs/customer/](docs/customer/getting-started.md) |
| Admin guide | [docs/admin/](docs/admin/platform-overview.md) |
| Developer guide | [docs/developer/](docs/developer/architecture-overview.md) |
| Deployment | [docs/deployment/](docs/deployment/vps-ubuntu.md) |
| Operations | [docs/operations/](docs/operations/monitoring.md) |

## Project Status
See [PROJECT-STATUS.md](PROJECT-STATUS.md) — Phases 1-7 complete, launch-ready.

## Security
See [docs/SECURITY-AUDIT.md](docs/SECURITY-AUDIT.md) — Phase 7 audit complete.
Critical and most high-severity issues resolved.
