# Deployment

This document lists deployment and environment setup guidance for the project.

## Development (local/XAMPP)

- Ensure MySQL is running. For XAMPP prefer `DB_HOST=127.0.0.1` and `DB_PORT=3306` in `.env` to force TCP.
- Install dependencies:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

- Run migrations and seeders:

```bash
php artisan migrate --force
php artisan db:seed
```

- Start local API server:

```bash
php -S 127.0.0.1:8000 -t public
```

## Production checklist

- Use a process manager (systemd, Supervisor) for queue workers.
- Run config and route caching as part of deploy:

```bash
php artisan config:cache
php artisan route:cache
```

- Run migrations during deploy with care and using `--force` in CI/CD pipelines.
- Use a dedicated DB server; configure backups for central and tenant DBs.
- Serve via NGINX with PHP-FPM behind HTTPS.

## Tenancy considerations

- Tenant DBs are created and migrated via `stancl/tenancy`.
- Creating a tenant store and seeding tenant data can be done with `FullDemoSeeder` (central seeder).

## Rollback & Maintenance

- Avoid destructive migrations on production without backups.
- Use maintenance mode for major migrations:

```bash
php artisan down
php artisan migrate --force
php artisan up
```
