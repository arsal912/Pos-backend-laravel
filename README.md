# POS System — Laravel Backend

Multi-tenant POS SaaS API built with Laravel 11.

## Quick Start

### 1. Install dependencies

```bash
composer install
```

### 2. Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set:
- Database credentials (`DB_*`)
- `FRONTEND_URL=http://localhost:3000`
- Payment gateway credentials (when ready)

### 3. Create database

Create a MySQL database called `pos_system` (or update `.env`).

### 4. Run migrations + seeders

```bash
php artisan migrate --seed
```

This creates:
- All tables
- 30 toggleable modules
- 4 subscription plans (Free Trial, Basic, Pro, Enterprise)
- Default landing page content
- Payment gateway records
- **Super admin: `admin@possystem.com` / `password`**

### 5. Run development server

```bash
php artisan serve
```

API runs at: `http://localhost:8000`

## API Documentation

### Base URL
```
http://localhost:8000/api/v1
```

### Authentication
All protected routes require Bearer token:
```
Authorization: Bearer <token>
```

### Key Endpoints

#### Public
- `GET /public/landing` — Get landing page data
- `GET /public/landing/status` — Quick on/off check
- `GET /public/landing/plans` — Get pricing plans

#### Auth
- `POST /auth/login` — Login
- `POST /auth/register` — Register new store
- `GET /auth/me` — Current user
- `POST /auth/logout` — Logout

#### Admin (Super Admin only)
- `GET /admin/dashboard` — Stats overview
- `GET /admin/stores` — List all stores
- `PUT /admin/landing-page/toggle` — Enable/disable landing page
- `GET /admin/modules/store/{storeId}` — Get module matrix for store
- `PUT /admin/modules/store/{storeId}/module/{moduleId}` — Toggle module for store
- `PUT /admin/modules/user/{userId}/module/{moduleId}` — Override module for user
- `GET /admin/api-logs` — View API logs

#### Store (Tenant users)
- `GET /store/test` — Test store endpoint
- More to be added in Phase 4+

## Architecture

### Multi-tenancy
Single database with `store_id` foreign key on tenant data. `TenantScope` middleware ensures users only access their store's data.

### Module Access Cascade
1. Super admin → unrestricted
2. User-level override (`user_modules` table)
3. Store-level setting (`store_modules` table)
4. Plan default (`plan_modules` table)

### API Logging
All API requests are auto-logged to `api_loggings` table with:
- User, store, endpoint, method
- Request/response payloads (sensitive fields masked)
- Duration in ms
- Errors and stack traces

Scheduled cleanup runs daily at 2am (retention from `.env`).

## Useful Commands

```bash
php artisan migrate:fresh --seed       # Reset DB
php artisan api-logs:purge --days=7    # Manual log cleanup
php artisan tinker                     # REPL
php artisan route:list                 # Show all routes
```
