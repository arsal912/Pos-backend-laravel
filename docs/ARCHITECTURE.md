# Architecture (High Level)

Overview of the system components and data separation.

```mermaid
graph LR
  Frontend[Frontend apps (web, PWA, POS)] -->|API| API[API Server (Laravel)]
  API --> CentralDB[Central DB]
  API --> TenantDBs[Tenants DBs (per-store)]
  API --> Queues[Queue workers]
  Queues --> External[External services: payments, SMS, email]
```

Key points
- Central DB: stores platform-level entities (`stores`, `users`, billing, aggregates).
- Tenant DBs: per-store data (products, inventory, sales, customers). Managed by `stancl/tenancy`.
- Queues and workers handle async jobs (reports, notifications, billing webhooks).
- Services layer (`app/Services`) encapsulates domain logic (stock, payments, reports).

Data flow
- API handles requests, consults central DB for store/tenant info and routes tenant-scoped operations to tenant DB.
- Tenant onboarding: create `Store` record in central DB, create tenant DB, run tenant migrations, seed tenant data.
