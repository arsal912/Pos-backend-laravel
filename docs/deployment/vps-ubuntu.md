# VPS Deployment Guide — Ubuntu 22.04

## Server Sizing
- Start: 2 vCPU, 4GB RAM, 80GB SSD (handles ~50 active stores)
- Scale: 4 vCPU, 8GB RAM when > 200 stores or heavy report usage

## 1. Initial Server Setup
```bash
# Log in as root, create deploy user
adduser deploy
usermod -aG sudo deploy

# Copy your SSH key
mkdir -p /home/deploy/.ssh
cp ~/.ssh/authorized_keys /home/deploy/.ssh/
chown -R deploy:deploy /home/deploy/.ssh

# Disable password auth
sed -i 's/PasswordAuthentication yes/PasswordAuthentication no/' /etc/ssh/sshd_config
systemctl restart sshd

# Firewall
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw enable

# fail2ban
apt install -y fail2ban
systemctl enable fail2ban
```

## 2. Install Dependencies
```bash
# Update system
apt update && apt upgrade -y

# PHP 8.2 + extensions
add-apt-repository ppa:ondrej/php -y
apt install -y php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml \
    php8.2-bcmath php8.2-curl php8.2-gd php8.2-zip php8.2-intl \
    php8.2-redis php8.2-opcache

# MySQL 8
apt install -y mysql-server
mysql_secure_installation

# Nginx
apt install -y nginx

# Node.js 20 LTS
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs

# Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Supervisor (queue workers)
apt install -y supervisor

# Certbot (SSL)
apt install -y certbot python3-certbot-nginx

# AWS CLI (for backups)
curl "https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip" -o awscliv2.zip
unzip awscliv2.zip && ./aws/install
```

## 3. MySQL Configuration
```sql
-- Create application database user
CREATE USER 'posapp'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
-- Must have CREATE DATABASE for tenant DB creation
GRANT ALL PRIVILEGES ON pos_system.* TO 'posapp'@'localhost';
GRANT CREATE, DROP ON *.* TO 'posapp'@'localhost';
-- Grant specific per-tenant as they're created (stancl/tenancy handles this)
FLUSH PRIVILEGES;

-- Create central database
CREATE DATABASE pos_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

## 4. Application Deployment
```bash
# Clone repos
cd /var/www
git clone https://github.com/YOUR_USERNAME/pos-backend.git
git clone https://github.com/YOUR_USERNAME/pos-frontend.git

# Backend setup
cd /var/www/pos-backend
composer install --no-dev --optimize-autoloader
cp .env.production.example .env
# Edit .env with production values
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force  # Seeds roles, modules, plans, super admin
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Frontend setup
cd /var/www/pos-frontend
npm ci
cp .env.production.example .env.local
# Edit .env.local
npm run build
```

## 5. SSL Certificates
```bash
# Issue certificates for all subdomains
certbot --nginx -d {DOMAIN} -d app.{DOMAIN} -d admin.{DOMAIN} -d api.{DOMAIN}
# Auto-renewal
systemctl enable certbot.timer
```

## 6. Supervisor (Queue Workers)
```bash
cp /var/www/pos-backend/config/supervisor/*.conf /etc/supervisor/conf.d/
supervisorctl reread
supervisorctl update
supervisorctl start all
```

## 7. Cron (Laravel Scheduler + Backups)
```bash
crontab -e -u deploy
# Add:
# * * * * * cd /var/www/pos-backend && php artisan schedule:run >> /dev/null 2>&1
# 0 2 * * * bash /var/www/pos-backend/scripts/backup/backup.sh
```

## 8. Nginx Setup
```bash
cp /var/www/pos-backend/config/nginx/*.conf /etc/nginx/sites-available/
ln -s /etc/nginx/sites-available/api.conf /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/app.conf /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/admin.conf /etc/nginx/sites-enabled/
ln -s /etc/nginx/sites-available/landing.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

## 9. Verification
Test each endpoint:
- curl https://api.{DOMAIN}/api/v1/health
- curl https://app.{DOMAIN}
- curl https://admin.{DOMAIN}
- curl https://{DOMAIN}
