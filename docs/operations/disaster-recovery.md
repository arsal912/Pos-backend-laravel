# Disaster Recovery Playbooks

This document contains step-by-step runbooks for the five most likely disaster scenarios
affecting the POS SaaS platform. Each scenario includes a detection signal, an immediate
15-minute triage window, full recovery steps, and an estimated downtime target.

---

## Scenario 1: Production (Central) Database Corrupted

### Detection Signal
- Laravel logs: `SQLSTATE[HY000]: General error` on multiple unrelated queries
- Tenants unable to log in; store listing API returns 500 errors
- `mysqlcheck -u root -p --all-databases` reports table errors on `pos_system`
- Monitoring alert: error rate spike > 20% on `/api/auth` or `/api/stores`

### Immediate Action (First 15 Minutes)
1. Put the application into maintenance mode to stop further writes:
   ```bash
   php artisan down --render="errors::503" --retry=300
   ```
2. Snapshot the corrupted DB before touching it (evidence + last resort):
   ```bash
   mysqldump -u root -p pos_system | gzip > /tmp/pos_system_corrupted_$(date +%Y%m%d%H%M).sql.gz
   ```
3. Identify the last known-good backup timestamp in S3:
   ```bash
   AWS_ACCESS_KEY_ID=$S3_KEY AWS_SECRET_ACCESS_KEY=$S3_SECRET \
     aws s3 ls s3://$S3_BUCKET/ | grep "pos-backup-pos_system-" | sort | tail -5
   ```
4. Notify on-call team and open a war-room channel.

### Recovery Steps
1. Download the most recent clean backup:
   ```bash
   AWS_ACCESS_KEY_ID=$S3_KEY AWS_SECRET_ACCESS_KEY=$S3_SECRET \
     aws s3 cp s3://$S3_BUCKET/pos-backup-pos_system-YYYY-MM-DD-HHMM.sql.gz /tmp/
   gunzip /tmp/pos-backup-pos_system-YYYY-MM-DD-HHMM.sql.gz
   ```
2. Drop and recreate the central database:
   ```bash
   mysql -u root -p -e "DROP DATABASE pos_system; CREATE DATABASE pos_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
3. Restore central DB:
   ```bash
   mysql -u root -p pos_system < /tmp/pos-backup-pos_system-YYYY-MM-DD-HHMM.sql
   ```
4. Restore ALL tenant databases from the exact same backup date (see restore.md).
5. Run Laravel migrations to apply any missing schema changes since backup:
   ```bash
   php artisan migrate --force
   ```
6. Verify integrity:
   ```bash
   mysql -u root -p pos_system -e "SELECT COUNT(*) FROM stores; SELECT COUNT(*) FROM users; SELECT COUNT(*) FROM plans;"
   ```
7. Bring the application back online:
   ```bash
   php artisan up
   ```
8. Monitor error rates for 30 minutes before closing the incident.

### Estimated Downtime
- Small deployment (< 10 tenants): 30–60 minutes
- Medium deployment (10–100 tenants): 1–3 hours
- Large deployment (100+ tenants): 3–6 hours (parallelise tenant restores across multiple shells)

---

## Scenario 2: Single Tenant Database Corrupted

### Detection Signal
- One store's users report errors; all other stores are unaffected
- Laravel logs show errors scoped to a single `pos_store_X` database
- Tenant-specific API endpoints return 500; central dashboard is healthy
- `mysqlcheck -u root -p pos_store_X` reports table corruption

### Immediate Action (First 15 Minutes)
1. Identify the affected tenant's database name:
   ```bash
   mysql -u root -p pos_system -e "SELECT id, name, tenancy_db_name FROM stores WHERE name LIKE '%STORE_NAME%';"
   ```
2. Snapshot the corrupted tenant DB:
   ```bash
   mysqldump -u root -p pos_store_X | gzip > /tmp/pos_store_X_corrupted_$(date +%Y%m%d%H%M).sql.gz
   ```
3. Optionally switch that tenant to a "store under maintenance" page without affecting others.
4. Find the most recent backup for that tenant in S3:
   ```bash
   AWS_ACCESS_KEY_ID=$S3_KEY AWS_SECRET_ACCESS_KEY=$S3_SECRET \
     aws s3 ls s3://$S3_BUCKET/ | grep "pos-backup-pos_store_X-" | sort | tail -5
   ```

### Recovery Steps
1. Download the tenant backup matching the date of the last known-good central DB backup:
   ```bash
   AWS_ACCESS_KEY_ID=$S3_KEY AWS_SECRET_ACCESS_KEY=$S3_SECRET \
     aws s3 cp s3://$S3_BUCKET/pos-backup-pos_store_X-YYYY-MM-DD-HHMM.sql.gz /tmp/
   gunzip /tmp/pos-backup-pos_store_X-YYYY-MM-DD-HHMM.sql.gz
   ```
2. Drop and recreate the tenant database:
   ```bash
   mysql -u root -p -e "DROP DATABASE pos_store_X; CREATE DATABASE pos_store_X CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   ```
3. Restore:
   ```bash
   mysql -u root -p pos_store_X < /tmp/pos-backup-pos_store_X-YYYY-MM-DD-HHMM.sql
   ```
4. Run tenant migrations:
   ```bash
   php artisan tenants:artisan "migrate --force" --tenant=STORE_ID
   ```
5. Verify row counts (products, orders, customers) against expected values.
6. Notify the affected store owner of the outage window and data recovery point.

### Estimated Downtime
- Affected tenant only: 20–45 minutes
- No impact on other tenants during the entire process

---

## Scenario 3: Server Compromised / Hacked

### Detection Signal
- Unexpected processes running (`top`, `ps aux`) or cron jobs you didn't create
- Outbound traffic spikes on the server NIC
- Laravel `.env` file modified timestamp changed unexpectedly
- SSH login alerts from unknown IPs
- Web application firewall (WAF) alerts for privilege escalation or shell injection
- Defaced pages or injected JavaScript in API responses

### Immediate Action (First 15 Minutes)
1. Isolate the server immediately — block all inbound/outbound traffic except your admin IP:
   ```bash
   # Block all inbound except your IP
   ufw default deny incoming
   ufw allow from YOUR_ADMIN_IP to any port 22
   ufw enable
   ```
2. Do NOT power off — preserve volatile memory for forensics if possible.
3. Rotate all secrets immediately from a clean machine:
   - Database passwords (`MYSQL_PASS`)
   - S3 access keys (`BACKUP_S3_KEY`, `BACKUP_S3_SECRET`)
   - Laravel `APP_KEY` (`php artisan key:generate` on the new server)
   - All OAuth tokens and third-party API keys stored in `.env`
4. Take a full disk snapshot via your cloud provider console (for forensics).
5. Spin up a fresh server from a known-good base image — do not attempt to clean the compromised one.

### Recovery Steps
1. Provision a clean server and install dependencies from scratch.
2. Pull application code from git (do not copy files from the compromised server).
3. Restore databases from the last pre-compromise backup (check backup timestamps against the estimated intrusion time).
4. Deploy fresh `.env` with all new rotated credentials.
5. Run `php artisan migrate --force` and `php artisan config:cache`.
6. Point DNS to the new server only after verifying the application is healthy.
7. Decommission (do not reuse) the compromised server.
8. File an incident report and notify affected store owners if any PII was potentially accessed.
9. Conduct a post-mortem to identify the attack vector and patch it before going live again.

### Estimated Downtime
- Preparation to new server live: 2–4 hours (assuming cloud provisioning)
- DNS propagation: up to 1 hour additional (mitigate with low TTL set proactively)

---

## Scenario 4: Cloud Provider Outage

### Detection Signal
- Cloud provider status page shows degraded or down services in your region
- All servers/databases unreachable simultaneously
- No application-level errors — infrastructure is simply gone
- S3 bucket also unreachable (backups unavailable from primary region)

### Immediate Action (First 15 Minutes)
1. Confirm on the cloud provider's status page that this is a regional outage, not a misconfiguration.
2. Check whether cross-region backups exist in a secondary S3 bucket or region.
3. Post a public status update (status page, social media, email to store owners) immediately:
   > "We are experiencing an outage due to infrastructure issues with our cloud provider.
   >  We are working to restore service. No data has been lost."
4. Estimate the provider's own RTO from their status page before starting a failover.

### Recovery Steps
1. If the outage is expected to last > 2 hours, initiate failover to the secondary region:
   a. Provision new servers in the secondary region.
   b. Restore databases from cross-region S3 backups (see restore.md).
   c. Update application `.env` with new database host and secondary S3 endpoint.
   d. Run `php artisan migrate --force` and `php artisan config:cache`.
2. Update DNS records to point to the secondary region servers.
3. Monitor for 30 minutes to confirm stability.
4. Once the primary region recovers:
   a. Export any data written during failover period.
   b. Merge or replay it into the primary region databases.
   c. Switch DNS back to primary region.
   d. Decommission temporary secondary servers.

### Cross-Region Backup Recommendation
Configure the backup script to upload to two S3 buckets in different regions by setting
`BACKUP_S3_BUCKET` to a primary bucket and adding a secondary sync step:

```bash
aws s3 sync s3://PRIMARY_BUCKET/ s3://SECONDARY_BUCKET/ --source-region us-east-1 --region eu-west-1
```

### Estimated Downtime
- Provider resolves within 2 hours: 0 minutes (wait it out)
- Failover to secondary region: 2–4 hours
- Full failover + DNS propagation: up to 5 hours

---

## Scenario 5: Ransomware / Mass Data Deletion

### Detection Signal
- Files on the server renamed with unfamiliar extensions (`.locked`, `.enc`, etc.)
- Databases dropped or tables truncated — application returns empty responses
- Ransom note file present in the application root or `/tmp`
- S3 bucket contents deleted or replaced with ransom notes
- Monitoring alerts: all tenant API endpoints returning empty arrays or 404

### Immediate Action (First 15 Minutes)
1. Immediately disconnect the server from the network (do not shut down if possible):
   ```bash
   ufw default deny incoming
   ufw default deny outgoing
   ufw enable
   ```
2. Do NOT pay the ransom. There is no guarantee of recovery and it funds further attacks.
3. Check whether S3 versioning is enabled — if so, deleted objects can be recovered:
   ```bash
   AWS_ACCESS_KEY_ID=$S3_KEY AWS_SECRET_ACCESS_KEY=$S3_SECRET \
     aws s3api list-object-versions --bucket $S3_BUCKET --prefix "pos-backup-" | grep "DeleteMarker"
   ```
4. Check S3 Object Lock status — if Object Lock (WORM) was enabled, backups cannot be deleted.
5. Preserve all attacker artifacts for forensics before rebuilding.

### Recovery Steps

#### If S3 backups are intact:
1. Provision a completely fresh server (do not recover the compromised one).
2. Follow the full restore procedure in restore.md, using the last known-good backup.
3. Rotate all credentials (see Scenario 3 steps 3–4).
4. Deploy fresh application code from git.
5. Bring up on new server, update DNS.

#### If S3 backups were also deleted (no versioning/Object Lock):
1. Check MySQL binary logs on the original server if they are still readable — they may allow point-in-time recovery even after a DROP DATABASE:
   ```bash
   mysqlbinlog /var/lib/mysql/binlog.* | grep -v "DROP DATABASE" > /tmp/binlog_recovery.sql
   mysql -u root -p < /tmp/binlog_recovery.sql
   ```
2. Check for any read replicas that may not have been compromised.
3. Contact the cloud provider — some providers can restore block storage volumes from
   automatic snapshots taken independently of your backup script.
4. If no recovery path exists, the data is unrecoverable. Rebuild from scratch and notify
   all store owners of the permanent data loss.

### Prevention (implement before an incident occurs)
- Enable S3 Object Lock (WORM mode) on the backup bucket — prevents deletion for the retention period
- Enable S3 Versioning as a secondary safeguard
- Restrict S3 credentials used by the backup script to write-only (no delete, no list-buckets)
- Store a separate read-only set of S3 credentials off-server for restore operations
- Enable MFA Delete on the S3 bucket so deletes require a second factor

### Estimated Downtime
- Backups intact, fresh server: 2–6 hours (size-dependent, same as Scenario 1)
- Backups deleted, binlog recovery: 4–12 hours (uncertain, partial recovery likely)
- No recovery path: indefinite (data loss — notify customers, rebuild)
