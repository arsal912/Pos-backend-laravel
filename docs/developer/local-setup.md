# Local Development Setup

## Prerequisites
- PHP 8.2+
- Composer
- MySQL 8.0+
- Node.js 20 LTS
- XAMPP or equivalent

## Backend Setup

```bash
cd C:\xampp\htdocs\pos-backend

# Install dependencies
composer install

# Environment
cp .env.example .env
php artisan key:generate

# Database — create central DB
mysql -u root -e "CREATE DATABASE pos_system;"

# Run migrations + seed
php artisan migrate
php artisan db:seed

# Start server
php artisan serve  # http://localhost:8000
```

## Frontend Setup

```bash
cd C:\xampp\htdocs\pos-frontend

npm install

cp .env.example .env.local
# Set: NEXT_PUBLIC_API_URL=http://localhost:8000/api/v1

npm run dev  # http://localhost:3000
```

## Default Credentials
- Super Admin: admin@possystem.com / password
- Demo Store: demo@demostore.com / password (run FullDemoSeeder first)

## Seeding Demo Data
```bash
php artisan db:seed --class=FullDemoSeeder
```
Creates a complete demo store with 15 products, 15 customers, 20 sales.
