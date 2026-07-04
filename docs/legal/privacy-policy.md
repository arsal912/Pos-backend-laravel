> **TEMPLATE ONLY — NOT LEGAL ADVICE.**
> This document is a starting-point template prepared for internal review purposes.
> It has NOT been reviewed by a qualified lawyer. Do NOT publish this document to
> end users until a licensed legal professional in the relevant jurisdiction(s) has
> reviewed, adapted, and approved it. Anthropic and the authors of this template
> accept no liability for any consequences arising from its use.

---

# Privacy Policy

**Product / Service:** [Your POS SaaS Product Name]
**Operator:** [Your Company Legal Name], registered in [City, Country]
**Effective date:** [DD Month YYYY]
**Last updated:** [DD Month YYYY]

---

## 1. Introduction

[Your Company Name] ("we", "us", "our") operates a multi-tenant cloud Point-of-Sale (POS) platform (the "Service"). This Privacy Policy explains how we collect, use, share, and protect personal data when you use the Service, and describes the rights you have under applicable law.

We are committed to compliance with:
- Pakistan's Personal Data Protection Law (PDPL) 2023 (and its implementing regulations)
- The European Union General Data Protection Regulation (EU) 2016/679 (GDPR), to the extent we process data of individuals located in the European Economic Area (EEA)

If there is a conflict between these frameworks, we apply the stricter standard.

---

## 2. Who This Policy Applies To

This policy applies to:
1. **Merchants (Tenants)** — businesses that subscribe to the Service and manage a store account.
2. **Store Customers** — end-customers whose personal data merchants enter into the Service (e.g., during a sale or loyalty programme enrolment).
3. **Merchant Staff** — employees of merchant businesses who are granted access to the Service.
4. **Website Visitors** — anyone who visits our marketing website or documentation.

Where merchants are the "data controller" for their customers' data, we act as a "data processor" on their behalf and are bound by our Data Processing Agreement (DPA). Merchants are responsible for ensuring they have a lawful basis to collect and share their customers' data with us.

---

## 3. Data We Collect

### 3.1 Data Merchants Provide About Store Customers

When a merchant creates or imports a customer record, the following personal data may be stored:

| Category | Fields |
|---|---|
| Identity | Full name, date of birth, gender |
| Contact | Email address, phone number, company name |
| Address | Billing address, shipping address, city, country |
| Financial / Tax | Tax identification number, account balance, credit limit |
| Transactional | Purchase history, payment methods used, loyalty points |
| Preferences | Notes, tags, referral code, communication preferences / opt-outs |

We do not store raw payment card numbers. Payment card data is handled exclusively by our PCI-DSS-compliant payment processors (see Section 5).

### 3.2 Data Collected Automatically from Merchant Users

When merchant staff log in and use the Service, we automatically collect:

- **Account data:** Name, email address, role, store association
- **Authentication data:** Hashed passwords, session tokens, two-factor authentication state
- **Usage logs:** API requests, feature interactions, timestamps, IP addresses
- **Device / browser data:** User-agent string, screen resolution (for UI optimisation)

### 3.3 Data Collected from Website Visitors

- IP address and approximate geolocation
- Browser type and version
- Pages visited and time spent
- Referrer URL

We do not use third-party tracking cookies or behavioural advertising pixels on our marketing website.

---

## 4. How We Use Personal Data

### 4.1 Service Delivery (Lawful basis: Contract / Legitimate Interest)

- Authenticating users and enforcing access controls
- Processing sales transactions and generating receipts
- Operating the loyalty / customer management module
- Providing customer support and troubleshooting

### 4.2 Communications (Lawful basis: Consent, or Legitimate Interest for transactional messages)

- Sending transactional emails (receipts, account alerts) via Resend
- Sending SMS notifications (OTPs, delivery alerts) via Twilio
- Sending WhatsApp business messages via a WhatsApp Business API provider

Marketing or promotional communications are sent only where the recipient has given explicit opt-in consent. Every marketing message includes a one-click unsubscribe mechanism. Opt-out requests are honoured within 48 hours and stored permanently to prevent re-subscription without fresh consent.

### 4.3 Analytics and Platform Improvement (Lawful basis: Legitimate Interest)

- Aggregated, de-identified usage analytics to improve product features
- Error and performance monitoring

We do not use store-customer personal data (Section 3.1) for cross-tenant analytics or to train AI/ML models without explicit merchant consent.

### 4.4 Legal Compliance (Lawful basis: Legal Obligation)

- Retaining transaction records for tax and accounting obligations
- Responding to lawful requests from courts or regulatory authorities
- Detecting and preventing fraud, abuse, or security incidents

---

## 5. Data Sharing and Third-Party Processors

We share personal data only as necessary to deliver the Service. We require all sub-processors to sign a Data Processing Agreement.

| Sub-processor | Purpose | Data Shared | Location |
|---|---|---|---|
| **Stripe** | Payment processing | Cardholder name, card token, billing address | USA (EU Standard Contractual Clauses where applicable) |
| **JazzCash / HBL** | Local payment processing (Pakistan) | Phone number, transaction amount | Pakistan |
| **Resend** | Transactional email delivery | Recipient email, email body | USA |
| **Twilio** | SMS / WhatsApp messaging | Recipient phone number, message body | USA (SCCs where applicable) |
| **[Cloud Hosting Provider]** | Infrastructure (database, storage, compute) | All data at rest | [Region — e.g., AWS ap-south-1] |

We do not sell personal data to third parties. We do not share data with advertisers.

For international transfers of EEA personal data, we rely on Standard Contractual Clauses (SCCs) adopted by the European Commission or the sub-processor's adequacy determination where available.

---

## 6. Data Retention

| Data Type | Retention Period | Legal Basis |
|---|---|---|
| Sales transaction records | 7 years from transaction date | Tax / accounting obligations (Pakistan Income Tax Ordinance; FBR requirements) |
| Customer personal data (PII) | Until merchant requests deletion, or 2 years after account closure | Contractual necessity |
| Authentication & session logs | 30 days rolling | Security / fraud detection (legitimate interest) |
| API access logs | 30 days rolling | Debugging and abuse prevention |
| Email / SMS delivery logs | 90 days | Deliverability troubleshooting |
| Backups | 30 days | Disaster recovery |

After expiry of the applicable retention period, data is either securely deleted or irreversibly anonymised.

Note: Transaction records cannot be fully deleted because they are required for tax compliance. When a store customer exercises their right to erasure, we anonymise all personal fields (name, email, phone, address, etc.) and replace them with a non-identifying reference (e.g., `DELETED-{id}`), while preserving the financial totals attached to the transaction.

---

## 7. Your Rights

Depending on your jurisdiction, you may have the following rights regarding your personal data:

| Right | Description |
|---|---|
| **Access** | Request a copy of the personal data we hold about you |
| **Rectification** | Request correction of inaccurate or incomplete data |
| **Erasure ("Right to be Forgotten")** | Request deletion / anonymisation of your personal data (subject to legal retention obligations) |
| **Data Portability** | Receive your data in a structured, machine-readable format (JSON or CSV) |
| **Objection** | Object to processing based on legitimate interests or for direct marketing |
| **Restriction** | Request that we restrict processing while a complaint is being resolved |
| **Withdraw Consent** | Withdraw previously given consent at any time without affecting the lawfulness of prior processing |

**How to exercise your rights:**
Submit a request to our privacy team at **privacy@[yourdomain.com]** or in writing to our registered address. We will respond within 30 days (PDPL) / one calendar month (GDPR), with one free-of-charge extension of up to two additional months for complex requests.

**Note for store customers:** If you are an end-customer of a merchant using this platform, please first contact the merchant directly, as they are the data controller for your personal data. We will assist merchants in fulfilling erasure requests via our API.

---

## 8. Security Measures

We implement the following technical and organisational security measures:

- **Encryption in transit:** TLS 1.2+ for all API and web traffic
- **Encryption at rest:** AES-256 database and storage encryption
- **Access controls:** Role-based access control (RBAC); principle of least privilege
- **Authentication:** Bcrypt-hashed passwords; optional two-factor authentication (TOTP)
- **Tenant isolation:** All data is logically isolated per tenant; cross-tenant access is prevented at the application layer
- **Vulnerability management:** Regular dependency updates; security scanning in CI/CD pipeline
- **Penetration testing:** [Annual / as required] third-party penetration tests
- **Incident response:** Documented breach-response procedure; regulators and affected users notified within 72 hours (GDPR Art. 33) or as required by PDPL

---

## 9. Cookies

We use cookies and similar storage technologies only for:

| Cookie | Purpose | Expiry |
|---|---|---|
| `session` / `sanctum_token` | Authentication session management | Session / 2 hours inactivity |
| `csrf_token` | Cross-site request forgery protection | Session |

We do not use:
- Third-party advertising or tracking cookies
- Analytics cookies (we use server-side aggregated analytics only)
- Social-media tracking pixels

Because we use only strictly necessary cookies, a cookie consent banner is not required under most jurisdictions. Should we ever introduce non-essential cookies, we will update this policy and implement an appropriate consent mechanism.

---

## 10. Children's Privacy

The Service is intended for use by businesses and their adult staff. We do not knowingly collect personal data from individuals under the age of 18. If you believe we have inadvertently collected data from a minor, please contact us immediately and we will delete it.

---

## 11. Changes to This Policy

We may update this Privacy Policy from time to time. Material changes will be notified to merchant account holders by email at least 30 days before they take effect. Continued use of the Service after the effective date constitutes acceptance of the revised policy.

---

## 12. Jurisdiction and Governing Law

This Privacy Policy is governed by the laws of the Islamic Republic of Pakistan. For EEA users, it is supplemented by GDPR requirements. Our supervisory authority for GDPR purposes is [Name of Lead EEA DPA if applicable, e.g., pending designation]. For PDPL matters, the competent authority is the National Commission for Personal Data Protection (NCPDP), Pakistan.

---

## 13. Contact Us

**Privacy Officer / Data Protection Contact:**
[Name]
[Your Company Legal Name]
[Registered Address]
[City, Pakistan]
Email: **privacy@[yourdomain.com]**
Phone: **[+92-XXX-XXXXXXX]**

To submit a formal data subject request, email the above address with the subject line "Data Subject Request — [Your Name]".

---

*Last reviewed by [Name, Title] on [Date].*
*Awaiting legal review before publication.*
