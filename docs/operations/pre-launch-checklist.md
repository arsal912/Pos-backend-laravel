# Pre-Launch Checklist

Complete every item before going live. Check off each item as you verify it.
Last reviewed: 2026-06-14

---

## 1. INFRASTRUCTURE

### Server
- [ ] VPS provisioned (Ubuntu 22.04 LTS, minimum 2 vCPU / 4 GB RAM)
- [ ] Server hardened (non-root deploy user, sudo only when needed)
- [ ] All OS packages updated (`apt update && apt upgrade -y`)
- [ ] Swap file configured (2 GB minimum)
- [ ] Server timezone set to `Asia/Karachi`

### Domain & DNS
- [ ] Production domain registered and pointed to server IP
- [ ] API subdomain configured (e.g., `api.yourdomain.com`)
- [ ] App subdomain configured (e.g., `app.yourdomain.com`)
- [ ] DNS propagation confirmed (`dig api.yourdomain.com`)

### SSL / HTTPS
- [ ] Let's Encrypt certificates issued for all subdomains
- [ ] Auto-renewal cron job verified (`certbot renew --dry-run` passes)
- [ ] HTTPS redirect enforced (HTTP 301 → HTTPS)
- [ ] HSTS header present in Nginx config
- [ ] SSL Labs grade A or better (https://www.ssllabs.com/ssltest/)

### Nginx
- [ ] Nginx installed and running (`systemctl status nginx`)
- [ ] Production server blocks in place for API and app subdomains
- [ ] Gzip compression enabled
- [ ] Client max body size set (e.g., `client_max_body_size 20M`)
- [ ] Nginx worker processes set to `auto`
- [ ] Static file caching headers configured
- [ ] Nginx config test passes (`nginx -t`)

### PHP-FPM
- [ ] PHP 8.2 installed with all required extensions
  - [ ] `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`
  - [ ] `curl`, `gd`, `zip`, `bcmath`, `intl`, `redis` (if used)
- [ ] PHP-FPM pool configured for deploy user
- [ ] `pm = dynamic` with appropriate `pm.max_children` for server RAM
- [ ] `upload_max_filesize` and `post_max_size` match Nginx limit
- [ ] OPcache enabled and configured in `php.ini`
- [ ] PHP-FPM running and socket/port reachable from Nginx

### MySQL
- [ ] MySQL 8.0 installed and running (`systemctl status mysql`)
- [ ] `mysql_secure_installation` completed (root password set, test DB removed)
- [ ] Central (landlord) database created
- [ ] Deploy user created with least-privilege grants
- [ ] `innodb_buffer_pool_size` tuned to ~70% of available RAM
- [ ] Binary logging enabled (for point-in-time recovery)
- [ ] MySQL slow query log enabled (`long_query_time = 1`)
- [ ] Connection limit reviewed (`max_connections`)

### Supervisor
- [ ] Supervisor installed and running
- [ ] Queue worker config in `/etc/supervisor/conf.d/pos-worker.conf`
- [ ] At least 2 worker processes configured
- [ ] Supervisor restarted after config change (`supervisorctl reread && supervisorctl update`)
- [ ] Workers confirmed running (`supervisorctl status`)
- [ ] Worker restart-on-failure configured (`autorestart=true`)

### Cron
- [ ] Laravel scheduler cron entry added for deploy user
  ```
  * * * * * cd /var/www/pos-backend && php artisan schedule:run >> /dev/null 2>&1
  ```
- [ ] Scheduled commands verified with `php artisan schedule:list`
- [ ] Cron running correctly (check `artisan schedule:run` output manually)

---

## 2. SECURITY

### Firewall
- [ ] UFW enabled (`ufw status` shows `Status: active`)
- [ ] Only required ports open: 22 (SSH), 80 (HTTP), 443 (HTTPS)
- [ ] MySQL port 3306 blocked from public internet
- [ ] UFW default deny incoming rule confirmed

### SSH
- [ ] SSH root login disabled (`PermitRootLogin no` in `sshd_config`)
- [ ] Password authentication disabled (`PasswordAuthentication no`)
- [ ] SSH key-based authentication working for deploy user
- [ ] SSH port changed from 22 if desired (update UFW rule accordingly)
- [ ] SSH service restarted after config change

### fail2ban
- [ ] fail2ban installed and running
- [ ] SSH jail enabled and active
- [ ] Nginx HTTP auth jail enabled (if applicable)
- [ ] Ban time and max retry configured appropriately
- [ ] fail2ban status checked (`fail2ban-client status`)

### Laravel Application
- [ ] `APP_DEBUG=false` in production `.env`
- [ ] `APP_ENV=production` in production `.env`
- [ ] `APP_KEY` is a unique, strong key (generated with `php artisan key:generate`)
- [ ] `.env` file not readable by web server (permissions: `600`)
- [ ] No `.env` file in git history (confirmed with `git log --all -- .env`)
- [ ] `APP_URL` set to production HTTPS URL
- [ ] `SESSION_SECURE_COOKIE=true` set
- [ ] `SANCTUM_STATEFUL_DOMAINS` limited to production frontend domain only

### Payment Gateway Credentials
- [ ] Stripe live keys configured (not test keys)
- [ ] PayPal live credentials configured
- [ ] JazzCash production merchant credentials configured
- [ ] Easypaisa production merchant credentials configured
- [ ] All gateway credentials verified by completing a real small transaction

### Webhooks
- [ ] Stripe webhook secret configured and signature verification enabled
- [ ] JazzCash webhook endpoint URL registered in JazzCash merchant portal
- [ ] Easypaisa webhook endpoint URL registered in Easypaisa portal
- [ ] Webhook endpoints tested and returning 200 for valid payloads

### CORS
- [ ] CORS allowed origins limited to production frontend domain only
- [ ] No wildcard `*` origin in production CORS config
- [ ] `config/cors.php` reviewed and matches production hostnames

### Content Security Policy (CSP)
- [ ] CSP header configured in Nginx or Laravel middleware
- [ ] CSP allows required external sources (Stripe.js, Sentry CDN, etc.)
- [ ] CSP does not use `unsafe-inline` or `unsafe-eval` unless strictly necessary
- [ ] CSP tested with browser console (no violations on normal app usage)

---

## 3. MONITORING

### Sentry — Backend
- [ ] Sentry DSN configured in backend `.env` (`SENTRY_LARAVEL_DSN`)
- [ ] Sentry Laravel SDK installed and `config/sentry.php` published
- [ ] Test exception triggers a Sentry event (`php artisan sentry:test`)
- [ ] Environment set to `production` in Sentry config
- [ ] Sentry traces sample rate configured (e.g., `0.1` for 10%)

### Sentry — Frontend
- [ ] Sentry DSN configured in frontend environment (`NEXT_PUBLIC_SENTRY_DSN`)
- [ ] Sentry Next.js SDK initialized in `_app.tsx` / `layout.tsx`
- [ ] Test error triggers a Sentry event (throw error in dev, verify in Sentry dashboard)
- [ ] Source maps uploaded to Sentry for production build
- [ ] Replays or performance tracing enabled (optional but recommended)

### Uptime Monitoring
- [ ] Uptime monitor configured (UptimeRobot, Better Uptime, or Freshping)
- [ ] Monitor checks: `/api/health` endpoint returns 200
- [ ] Monitor checks: frontend home page returns 200
- [ ] Check interval set to 1 minute (or lowest available on free plan)
- [ ] Alert contacts verified (see below)

### Alert Contacts
- [ ] Your email added as alert contact in uptime monitor
- [ ] Your WhatsApp/phone number added as alert contact (SMS alerts)
- [ ] Sentry alert rules configured (alert on new issues, spike in errors)
- [ ] Test alert confirmed received on your phone/email

### Test Alert
- [ ] Manually triggered a downtime alert (temporarily stopped Nginx, confirmed alert received, restarted Nginx)
- [ ] Alert resolution notification also received

---

## 4. BACKUPS

### Backup Script
- [ ] Backup script exists at `scripts/backup.sh`
- [ ] Script backs up all tenant databases + central database
- [ ] Script backs up uploaded files (storage/app)
- [ ] Script tested manually end-to-end without errors
- [ ] Backup file naming includes timestamp

### S3 / Remote Storage
- [ ] AWS S3 bucket created (or DigitalOcean Spaces, Backblaze B2, etc.)
- [ ] Bucket versioning enabled
- [ ] Bucket lifecycle rule configured (delete backups older than 30 days)
- [ ] AWS IAM user created with least-privilege S3 access
- [ ] S3 credentials in server environment (not in application `.env`)
- [ ] Backup script uploads to S3 successfully (tested)

### First Backup
- [ ] First full backup completed and verified in S3
- [ ] Backup file size is non-zero and reasonable

### Restore Test
- [ ] Full restore tested on a staging or local environment
- [ ] Restore procedure documented in `docs/operations/restore.md`
- [ ] Disaster recovery runbook reviewed (`docs/operations/disaster-recovery.md`)

### Cron Schedule
- [ ] Backup cron job configured to run nightly (e.g., 2:00 AM)
  ```
  0 2 * * * /var/www/pos-backend/scripts/backup.sh >> /var/log/pos-backup.log 2>&1
  ```
- [ ] Log file is being written and shows successful runs

---

## 5. BUSINESS READINESS

### Payment Gateways
- [ ] Stripe account fully verified (business name, bank account, ID)
- [ ] PayPal business account verified
- [ ] JazzCash merchant account approved and live
- [ ] Easypaisa merchant account approved and live
- [ ] Real test transaction of PKR 100 completed through each active gateway
- [ ] Refund flow tested for at least one gateway

### Webhooks (Business)
- [ ] Subscription renewal webhooks tested (simulate upcoming invoice)
- [ ] Failed payment webhook tested (Stripe — payment failed event)
- [ ] Subscription cancellation webhook tested

### Email Domain
- [ ] Sending domain verified in Resend (or chosen email provider)
- [ ] SPF record published in DNS
- [ ] DKIM record published in DNS
- [ ] DMARC record published in DNS
- [ ] Test transactional email delivered to inbox (not spam)
- [ ] From address uses your domain (not a free email provider)

### Legal & Policy
- [ ] Privacy Policy published at `yourdomain.com/privacy`
- [ ] Terms of Service published at `yourdomain.com/terms`
- [ ] Refund / Cancellation policy reviewed and accurate
- [ ] Data retention policy defined and documented

### Pricing
- [ ] Subscription plans finalized (name, price, features)
- [ ] Plans configured in admin panel
- [ ] Pricing page on marketing site matches plans in system
- [ ] Free trial duration confirmed (if offering one)
- [ ] Currency set to PKR for local customers; USD for international (if applicable)

---

## 6. CUSTOMER SUPPORT

### Support Channel
- [ ] Support email address created (e.g., `support@yourdomain.com`)
- [ ] Support email forwarded to your personal inbox (so you don't miss it)
- [ ] Auto-reply configured for support email (acknowledge within 24 hours)
- [ ] Support email address visible in the app (help/settings section)

### Help Documentation
- [ ] Getting started guide published
- [ ] POS (sales) how-to guide published
- [ ] Inventory management guide published
- [ ] Reports guide published
- [ ] Offline mode guide published
- [ ] Docs hosted at accessible URL (e.g., `docs.yourdomain.com` or Notion)

### FAQ
- [ ] FAQ covers top 10 expected questions
- [ ] FAQ includes: How to add products, How to process a sale, How to issue a refund, Offline mode explained, How to print receipts, How billing works
- [ ] FAQ published and linked from the app

### Onboarding Email Sequence
- [ ] Welcome email (Day 0): sent immediately after registration
- [ ] Getting started email (Day 1): link to first steps
- [ ] Feature highlight email (Day 3): show one key feature
- [ ] Check-in email (Day 7): ask if they need help
- [ ] Email sequence tested end-to-end with a test account

### Demo Account
- [ ] Demo tenant created with pre-loaded sample data
- [ ] Demo account credentials documented for sales use
- [ ] Demo resets to clean state periodically (or manually before demos)

---

## 7. OBSERVABILITY

### Database Performance
- [ ] Slow query log analyzed (review queries over 1 second)
- [ ] `EXPLAIN` run on top 5 slowest queries
- [ ] Missing indexes added for frequent query patterns
- [ ] N+1 queries identified and resolved (Debugbar used in staging)
- [ ] Database query count on POS sale endpoint below 20 queries

### Frontend Performance
- [ ] Lighthouse audit run on production URL (Chrome DevTools)
- [ ] Performance score >= 80
- [ ] Accessibility score >= 90
- [ ] Best Practices score >= 90
- [ ] SEO score >= 80 (for public pages)
- [ ] First Contentful Paint (FCP) < 2.0 seconds on 4G
- [ ] Largest Contentful Paint (LCP) < 3.0 seconds on 4G
- [ ] Cumulative Layout Shift (CLS) < 0.1

### First Load / Cold Start
- [ ] App first load tested on a slow 3G connection (Chrome throttling)
- [ ] Service worker caches critical assets on first visit
- [ ] Offline mode banner appears when network is disconnected
- [ ] First offline sale completes without errors after initial load

### API Response Times
- [ ] POS sale endpoint (`POST /api/tenant/pos/sales`) responds in < 500ms
- [ ] Product search endpoint responds in < 300ms
- [ ] Dashboard summary endpoint responds in < 1 second
- [ ] API response times checked under concurrent load (even basic `ab` test)

---

## 8. LAUNCH READINESS

### Beta Customer
- [ ] At least 1 beta customer onboarded and actively using the POS
- [ ] Beta customer has completed at least 10 real sales
- [ ] No critical bugs reported in the last 7 days
- [ ] Beta customer feedback collected and reviewed
- [ ] Top feedback items addressed or documented as known issues

### Feedback Loop
- [ ] In-app feedback widget or email visible to users
- [ ] Feedback goes to a monitored inbox or channel
- [ ] Process defined for triaging and responding to bug reports

### Announcement
- [ ] Launch announcement drafted (email, WhatsApp broadcast)
- [ ] Announcement mentions: what the product does, who it is for, how to start, pricing
- [ ] Launch announcement reviewed by at least one other person

### Social Media
- [ ] LinkedIn post drafted and scheduled
- [ ] Facebook/Instagram post drafted (if targeting Pakistani retail market)
- [ ] WhatsApp broadcast list prepared (your network)
- [ ] Product Hunt draft created (if planning a PH launch)

### Final Smoke Test (Day of Launch)
- [ ] Fresh registration flow completed end-to-end
- [ ] Subscription payment completed (real money)
- [ ] First sale processed on the POS
- [ ] Receipt generated correctly
- [ ] Inventory updated after sale
- [ ] Reports show the sale
- [ ] Offline sale completed and synced
- [ ] Support email confirmed reachable

---

*Sign-off: All items above checked before go-live.*

| Role | Name | Date |
|------|------|------|
| Developer / Founder | | |
