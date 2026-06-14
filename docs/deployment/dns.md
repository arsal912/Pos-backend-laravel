# DNS Configuration Guide

## Required DNS Records

Point these to your VPS IP address:

| Type | Name              | Value      | TTL  |
|------|-------------------|------------|------|
| A    | {DOMAIN}          | VPS_IP     | 300  |
| A    | app.{DOMAIN}      | VPS_IP     | 300  |
| A    | admin.{DOMAIN}    | VPS_IP     | 300  |
| A    | api.{DOMAIN}      | VPS_IP     | 300  |

Or use a wildcard:

| Type | Name              | Value      | TTL  |
|------|-------------------|------------|------|
| A    | {DOMAIN}          | VPS_IP     | 300  |
| A    | *.{DOMAIN}        | VPS_IP     | 300  |

## Optional: Cloudflare Setup

Using Cloudflare as DNS proxy adds:
- DDoS protection
- CDN caching for static assets
- Free SSL (if not using Let's Encrypt)

Setup:
1. Add domain to Cloudflare (free plan)
2. Update nameservers at your registrar
3. Set all records to "Proxied" (orange cloud)
4. In nginx, trust Cloudflare IPs for real-IP header:

```nginx
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
real_ip_header CF-Connecting-IP;
```

## Email DNS (for Resend)

Add these for email deliverability:

| Type  | Name               | Value                                          |
|-------|--------------------|------------------------------------------------|
| TXT   | {DOMAIN}           | v=spf1 include:_spf.resend.com ~all            |
| CNAME | resend._domainkey  | (from Resend dashboard)                        |
| TXT   | _dmarc.{DOMAIN}    | v=DMARC1; p=none; rua=mailto:dmarc@{DOMAIN}   |
