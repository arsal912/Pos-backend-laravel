# Data Retention and Deletion Policy

**Product:** [Your POS SaaS Product Name]
**Owner:** [Your Company Legal Name]
**Effective date:** [DD Month YYYY]
**Last updated:** [DD Month YYYY]

This document is an internal operations reference and a public-facing summary for customers. It defines what data is retained, for how long, why, and how customers can request deletion.

---

## 1. Retention Schedule

### 1.1 Financial and Transaction Data

| Data | Retention Period | Legal Basis | Deletion Method |
|---|---|---|---|
| Sales transaction records (amounts, taxes, line items) | **7 years** from transaction date | Pakistan Income Tax Ordinance 2001; FBR sales tax record-keeping requirements | Anonymised (personal fields stripped); financial totals preserved |
| Payment method tokens / references | **7 years** (PCI-DSS requirement for dispute resolution) | PCI-DSS v4 requirement 9.4 | Deleted after expiry |
| Invoices and receipts | **7 years** | Pakistan Companies Act 2017; tax law | Retained; customer identity anonymised on erasure request |

### 1.2 Customer Personal Data (PII)

| Data | Retention Period | Legal Basis | Deletion Method |
|---|---|---|---|
| Customer name, email, phone, address, DOB | Until merchant requests deletion, or **2 years after merchant account closure** | Contract (Service delivery) | Anonymised via hard-delete endpoint (name replaced with `DELETED-{id}`, all PII fields set to null) |
| Customer loyalty points balance | Until customer erasure or account closure | Contractual necessity | Deleted with customer record |
| Communication opt-outs (sms/email/whatsapp) | **Indefinitely** | Legitimate interest (preventing re-subscription of opted-out individuals) | Retained to honour opt-out; recipient field replaced with anonymised reference on erasure |
| Customer tags and notes | Until merchant requests deletion | Merchant-controlled | Nullified on hard-delete |

### 1.3 Merchant / Staff Account Data

| Data | Retention Period | Legal Basis | Deletion Method |
|---|---|---|---|
| Staff account records (name, email, role) | Until account termination + **30 days** export window | Contract | Deleted |
| Audit logs of staff actions | **1 year** | Legitimate interest (fraud detection, dispute resolution) | Deleted after expiry |

### 1.4 System and Security Logs

| Data | Retention Period | Legal Basis | Deletion Method |
|---|---|---|---|
| API request logs (IP, endpoint, timestamp) | **30 days** rolling | Security / abuse prevention (legitimate interest) | Automatically purged by log rotation |
| Authentication / session logs | **30 days** rolling | Security (legitimate interest) | Automatically purged |
| Email delivery logs (Resend) | **90 days** | Deliverability troubleshooting (legitimate interest) | Purged at sub-processor level |
| SMS delivery logs (Twilio) | **90 days** | Same as above | Purged at sub-processor level |
| Error / exception logs | **30 days** rolling | Debugging (legitimate interest) | Automatically purged |

### 1.5 Backups

| Data | Retention Period | Legal Basis | Notes |
|---|---|---|---|
| Database backups | **30 days** rolling | Disaster recovery (legitimate interest) | Older backups are overwritten; deletion requests satisfied from live data immediately; backup purge completes within 30 days |
| File / media backups | **30 days** rolling | Same | Same as above |

---

## 2. Why Certain Data Cannot Be Fully Deleted

### 2.1 Tax and Accounting Compliance

Pakistani law requires that businesses retain records of financial transactions for a minimum of 7 years. This includes:
- The gross value of each sale
- Tax amounts charged (GST, withholding)
- Date and payment method

Because this data is required by law, it is **impossible to fully delete a transaction record**. When a store customer exercises their right to erasure, we anonymise all personal identifying fields attached to the transaction (name, email, phone, address) while retaining the financial figures. The anonymised record satisfies legal retention requirements without identifying the individual.

### 2.2 Communication Opt-Outs

Deleting an opt-out record would risk sending marketing communications to someone who has opted out. For this reason, opt-out records are retained indefinitely, with the recipient identifier replaced by a non-reversible anonymised reference upon erasure.

### 2.3 Backups

Backup media is rotated on a 30-day cycle. A deletion request is honoured on live production data immediately; the change will propagate to backups within the standard 30-day rotation period.

---

## 3. How Customers Can Request Deletion

### 3.1 Store Customers (Data Subjects)

If you are an end-customer of a business that uses this platform:

1. **Contact the merchant directly** — they are the data controller and can process erasure requests via the admin dashboard.
2. If the merchant is unresponsive or no longer operating, contact us at **privacy@[yourdomain.com]** with the subject line "Data Subject Erasure Request". Provide your full name, the name of the merchant store, and any transaction reference numbers.
3. We will respond within **30 days** confirming the anonymisation.

### 3.2 Merchants Requesting Deletion of Their Account

1. Navigate to **Settings > Account > Close Account** in the dashboard, or contact **support@[yourdomain.com]**.
2. You have a **30-day data export window** before deletion proceeds. Use this time to download any reports you need.
3. After 30 days, all non-legally-required data is deleted. Transaction financial records are anonymised and retained for 7 years.

### 3.3 API-Driven Erasure (for Tenant Administrators)

Merchants with API access can trigger erasure programmatically:

```
DELETE /api/store/customers/{id}?hard=true
Authorization: Bearer {token}
```

This endpoint:
- Anonymises all PII fields on the customer record
- Adds the customer to communication opt-out lists for sms, email, and whatsapp channels
- Soft-deletes the record (preserving the row for audit trail and transaction linkage)
- Returns a confirmation message

The `hard=true` parameter requires the `manage-customers` permission. It is irreversible.

### 3.4 Data Portability (Export) Request

To receive a copy of all personal data held about you or your customers:

- Email **privacy@[yourdomain.com]** with subject "Data Portability Request"
- We will deliver a structured JSON or CSV export within **5 business days**

---

## 4. Review and Governance

This policy is reviewed annually (or when relevant laws change) by the [Data Protection Officer / Privacy Officer]. Changes require sign-off from [Legal Counsel / Managing Director].

**Next scheduled review:** [DD Month YYYY]

---

*Questions about this policy? Contact **privacy@[yourdomain.com]**.*
