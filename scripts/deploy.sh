#!/bin/bash
# Manual deployment script for VPS
# Usage: bash scripts/deploy.sh
set -euo pipefail

echo "=== POS Deploy Starting ==="
DEPLOY_TIME=$(date -u +%Y-%m-%dT%H:%M:%SZ)

# --- Backend ---
echo "[1/8] Pulling backend..."
cd /var/www/pos-backend
git pull origin main

echo "[2/8] Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction

echo "[3/8] Running migrations..."
php artisan migrate --force

echo "[4/8] Caching config/routes/views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

echo "[5/8] Restarting queue workers..."
php artisan queue:restart

# --- Frontend ---
echo "[6/8] Pulling frontend..."
cd /var/www/pos-frontend
git pull origin main

echo "[7/8] Installing JS dependencies..."
npm ci --omit=dev

echo "[8/8] Building frontend..."
npm run build

# Restart Next.js (adjust if using pm2 or systemd)
if command -v pm2 &> /dev/null; then
    pm2 restart pos-frontend
elif systemctl is-active --quiet pos-frontend; then
    systemctl restart pos-frontend
fi

echo ""
echo "=== Deploy complete at $DEPLOY_TIME ==="
echo "Run 'nginx -t && systemctl reload nginx' if nginx config changed."
