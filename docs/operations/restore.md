# Database Restore Procedures

## Prerequisites
- Access to S3 bucket with backups
- mysql client installed
- Sufficient disk space for decompressed dump

## Restore Central Database (pos_system)

```bash
# 1. Download backup from S3
AWS_ACCESS_KEY_ID=$S3_KEY AWS_SECRET_ACCESS_KEY=$S3_SECRET \
  aws s3 cp s3://$S3_BUCKET/pos-backup-pos_system-YYYY-MM-DD-HHMM.sql.gz /tmp/

# 2. Decompress
gunzip /tmp/pos-backup-pos_system-YYYY-MM-DD-HHMM.sql.gz

# 3. Create fresh DB (or drop existing - DANGEROUS)
mysql -u root -p -e "DROP DATABASE IF EXISTS pos_system; CREATE DATABASE pos_system;"

# 4. Restore
mysql -u root -p pos_system < /tmp/pos-backup-pos_system-YYYY-MM-DD-HHMM.sql

# 5. Verify
mysql -u root -p pos_system -e "SELECT COUNT(*) FROM stores; SELECT COUNT(*) FROM users;"
```

> WARNING: Always restore tenant DBs from the SAME backup date as the central DB.
> Cross-date restores risk orphaned store records or missing tenant databases.

## Restore Single Tenant Database

```bash
# Find the store's database name
mysql -u root -p pos_system -e "SELECT id, name, tenancy_db_name FROM stores WHERE id = STORE_ID;"

# Download and restore that tenant's backup
AWS_ACCESS_KEY_ID=$S3_KEY AWS_SECRET_ACCESS_KEY=$S3_SECRET \
  aws s3 cp s3://$S3_BUCKET/pos-backup-pos_store_X-YYYY-MM-DD-HHMM.sql.gz /tmp/

gunzip /tmp/pos-backup-pos_store_X-YYYY-MM-DD-HHMM.sql.gz
mysql -u root -p -e "DROP DATABASE IF EXISTS pos_store_X; CREATE DATABASE pos_store_X;"
mysql -u root -p pos_store_X < /tmp/pos-backup-pos_store_X-YYYY-MM-DD-HHMM.sql
```

## Quarterly Restore Drill
Run this every 3 months to verify backups are restorable:
1. Download most recent backup
2. Restore to a test database (use name pos_system_test)
3. Verify row counts match expected
4. Delete test database
5. Document the drill in a changelog

## Restore Decision Tree
```
Data loss / corruption detected?
├── Central DB only → Restore pos_system + ALL tenant DBs from same date
├── Single tenant DB only → Restore just that tenant's DB
├── All data gone → Full restore: central first, then all tenants
└── Recent accidental delete → Check if soft-deleted (customers, products use SoftDeletes)
    └── Soft-deleted? → Use withTrashed() to restore in Tinker
    └── Hard deleted? → Restore from backup
```
