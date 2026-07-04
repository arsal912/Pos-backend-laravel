# Project Overview — POS Backend (Laravel)

This document gives a concise orientation for developers working on this repository.

**Purpose**
- Backend API for a multi-tenant Point-of-Sale system (central + tenant DBs).

**Quick start (development)**
1. Install dependencies:

   composer install

2. Copy and edit environment:

   cp .env.example .env
   # Use `DB_HOST=127.0.0.1` (not `localhost`) to force TCP when using XAMPP

3. Generate app key and run migrations:

   See the split documentation files for more details:

   - [DEPLOYMENT.md](docs/DEPLOYMENT.md)
   - [ARCHITECTURE.md](docs/ARCHITECTURE.md)
   - [CONTRIBUTING.md](docs/CONTRIBUTING.md)

- Production (recommended checklist):
   - Use a process manager (systemd or Supervisor) to run queue workers and horizon.
   - Run `php artisan config:cache` and `php artisan route:cache` as part of deploy steps.
   - Use a separate database server per environment; ensure regular backups of central and tenant DBs.
   - Configure SSL and reverse proxy (NGINX recommended) to expose the API behind HTTPS.

## Architecture (high level)

```mermaid
graph LR
   A[Frontend apps] -->|API requests| B[API Server (this repo)]
   B --> C[Central DB (stores, users, billing)]
   B --> D[Tenants DBs (per-store)]
   B --> E[Queue workers]
   E --> F[External services (payment gateways, mail, SMS)]
   style B fill:#f9f,stroke:#333,stroke-width:1px
```

Notes:
- Central DB holds platform-level entities: `stores`, billing, central users and aggregates.
- Tenants DBs (created via `stancl/tenancy`) contain store-specific data: products, sales, inventory, customers.

## Contributor Guidelines

- Branching:
   - Use feature branches off `development` (e.g., `feature/add-reporting`).
   - Open PRs against `development` and include a short description and testing notes.

- Commits:
   - Use imperative style: "Add X", "Fix Y"; keep messages concise.

- Coding standards:
   - Follow PSR-12 for PHP style. Run `composer fix` or `php-cs-fixer` if configured.
   - Add tests for new features where practical (feature/unit tests under `tests/`).

- Database changes:
   - Add central migrations to `database/migrations/` and tenant migrations to `database/migrations/tenant/`.
   - Prefer additive migrations; do destructive changes with care and migration rollbacks.

- Local testing tips:
   - Use `FullDemoSeeder` to create a demo store and realistic tenant data.
   - Use `php artisan tenants:migrate --tenants={tenant-id} --force` to apply tenant migrations to a created tenant.

---
If you'd like, I can split Deployment, Architecture, and CONTRIBUTING into separate files and add CI/CD examples (GitHub Actions). Which would you prefer?
