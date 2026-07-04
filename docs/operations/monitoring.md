# Monitoring & Observability

## Health Checks

### Basic Health (Public)
GET /api/v1/health
Returns: {"status": "ok", "ts": "ISO-timestamp"}
Use for uptime monitors (UptimeRobot, Better Stack).

### Detailed Health (Token-Protected)
GET /api/v1/health/detail
Header: X-Health-Token: {HEALTH_CHECK_TOKEN}
Returns: database status, queue depth, failed job count.
Returns 503 if any service is degraded.

## Sentry Error Tracking

### Backend Setup
1. Sign up at https://sentry.io (free tier: 5K errors/month)
2. Create a new project: Laravel
3. Copy your DSN
4. Add to .env: SENTRY_LARAVEL_DSN=https://xxx@sentry.io/yyy
5. Run: composer require sentry/sentry-laravel
6. Run: php artisan sentry:publish --dsn=your-dsn
7. Test: php artisan sentry:test

### Frontend Setup
1. In same Sentry account, create another project: Next.js
2. Add to .env.local: NEXT_PUBLIC_SENTRY_DSN=https://xxx@sentry.io/yyy
3. Run: npm install @sentry/nextjs
4. Run: npx @sentry/wizard@latest -i nextjs
5. Test by triggering an error in the UI

### What Sentry Captures
- Backend: All uncaught exceptions, slow queries (traces_sample_rate=0.1 = 10% of requests)
- Frontend: JavaScript errors, unhandled promise rejections, React component errors
- NOT captured: auth failures (expected), validation errors (expected)

## Uptime Monitoring

### Recommended: UptimeRobot (Free Tier)
1. Sign up at https://uptimerobot.com
2. Add monitor: HTTP(s) → https://api.{DOMAIN}/api/v1/health
3. Check interval: 5 minutes (free tier max)
4. Alert contacts: your email + phone SMS
5. Add same monitor for https://app.{DOMAIN} and https://{DOMAIN}

### Recommended: Better Stack (Free for 3 monitors)
Better latency, 1-min checks, beautiful status page.
https://betterstack.com

### Alert Conditions
- Down for > 5 minutes: email + SMS
- Response time > 5s: email only
- SSL cert expires < 14 days: email

## Log Access in Production

### Laravel Logs
```bash
# Real-time
tail -f /var/www/pos-backend/storage/logs/laravel.log

# Search for errors
grep -i "error\|exception" /var/www/pos-backend/storage/logs/laravel.log | tail -50

# via journalctl (if using systemd php-fpm)
journalctl -u php8.2-fpm -f
```

### Nginx Logs
```bash
tail -f /var/log/nginx/api.{DOMAIN}.access.log
tail -f /var/log/nginx/api.{DOMAIN}.error.log
```

### Queue Worker Logs (Supervisor)
```bash
tail -f /var/log/supervisor/pos-queue.out.log
tail -f /var/log/supervisor/pos-queue.err.log
```

## SLO & Error Budget
- Target SLO: 99.5% uptime = max 3.6 hours downtime/month
- Track: UptimeRobot monthly report + Sentry error volume
- When SLO breached: complete post-mortem within 48 hours

## Post-Mortem Template
```
## Incident: [Short Title] — [Date]
**Duration:** X hours Y minutes
**Impact:** [What was affected, how many stores]
**Root Cause:** [What actually caused it]
**Timeline:** [UTC timestamps of: detection, diagnosis, fix, recovery]
**Fix Applied:** [What was changed]
**Prevention:** [What will stop this happening again]
```
