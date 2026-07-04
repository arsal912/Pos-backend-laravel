# Beta Customer Onboarding Playbook

Version 1.0 — 2026-06-14

---

## Overview

Beta customers are the first real users of the POS. They validate that the product works in a real shop environment, surface bugs you cannot find in testing, and become your first word-of-mouth advocates if you treat them well.

Target: **3–5 small-to-medium retail shops in Pakistan**

Ideal beta customer profile:
- Owner-operated retail shop (electronics, clothing, grocery, pharmacy, general store)
- Currently using a manual system, Excel, or a basic cash register
- Comfortable using a smartphone or tablet
- Located in a city where you can visit in person at least once

---

## Finding Beta Customers

### Where to look
- **Personal network** — friends, family members, relatives who own shops. Start here. Trust is already established.
- **Local WhatsApp groups** — business owner groups in your city. Introduce yourself and offer to demo the system.
- **Facebook groups** — search for "Pakistani retailers", "dukandaar Pakistan", or category-specific groups (electronics dealers, clothing shops).
- **Physical visits** — walk into shops in electronics markets, clothing bazaars, or shopping centres in your city. Ask for the owner, introduce yourself, offer a free demo on the spot.
- **Business associations** — local chambers of commerce, trade associations, or market committees often have WhatsApp groups.

### What to offer

Free use for **3 months** in exchange for:
1. One weekly 15-minute check-in call (WhatsApp or phone)
2. Honest feedback via WhatsApp whenever something is confusing or broken
3. Permission to mention them as a reference customer (by shop name, not personal name if they prefer)

### What to say (pitch script)
> "I've built a POS system specifically for Pakistani shops — it works offline, handles Pakistani payment methods, and is much cheaper than alternatives. I'm looking for 3–5 shop owners to try it for free for 3 months. In return I just need honest feedback. Can I show you a 10-minute demo?"

---

## Before You Reach Out

Have these ready before approaching any beta customer:
- [ ] A working demo you can show on your phone in 5 minutes
- [ ] A one-page reference card (screenshot-based, in Urdu if possible)
- [ ] A WhatsApp number dedicated to support (your personal number is fine for beta)

---

## Beta Onboarding Session (1 hour)

### Before the session (30 minutes of prep)

1. Create their account manually via `/register` (use their business name, a temporary password you'll hand over)
2. Add 10–20 of their most common products from a list they send you before the session (WhatsApp them a simple format: product name, price, barcode if any)
3. Install the PWA on their tablet or phone:
   - Open the app URL in Chrome
   - "Add to Home Screen" via Chrome menu
   - Verify the app icon appears on the home screen
4. Complete one test sale yourself before they arrive to confirm the setup works
5. Bring a charger for the tablet

### During the session

**Part 1 — POS walkthrough (15 min)**
- Show the sales screen
- Do 3 test sales together: cash payment, one with a discount, one with a return
- Let them do the 3rd sale themselves with minimal guidance
- Key message: "This is what you will do 50 times a day. It takes 30 seconds."

**Part 2 — Inventory management (10 min)**
- Show how to add a product
- Show how to adjust stock
- Show the low-stock alert
- Key message: "You will know when to reorder before you run out."

**Part 3 — Reports (5 min)**
- Show today's sales summary
- Show the daily sales report
- Key message: "At the end of the day, you can see exactly what you sold and how much cash you should have."

**Part 4 — Offline mode (5 min)**
- Turn off the tablet's WiFi
- Complete a sale
- Turn WiFi back on
- Show the sale appearing in reports
- Key message: "Even if the internet goes down, you never stop selling. It syncs automatically when you reconnect."

**Part 5 — Receipt printing (10 min, if they have a printer)**
- Connect the Bluetooth or USB thermal printer
- Print a test receipt
- Adjust receipt header (shop name, phone number)
- Key message: "Professional receipts build customer trust."

**Part 6 — Questions and concerns (15 min)**
- Let them ask anything
- Take notes on every question — these become FAQ entries
- Do not oversell features that are not ready
- Be honest about limitations (e.g., "FBR integration is coming in the next version")

### After the session (same day)

Send a WhatsApp message containing:
1. Login URL: `https://app.yourdomain.com`
2. Their username / email
3. Their temporary password (remind them to change it)
4. Link to the quick reference card (a screenshot PDF)
5. Your WhatsApp number for support
6. Scheduled time for the first weekly check-in

---

## Weekly Check-In Structure (15 minutes)

Run this every week for the first 4 weeks.

**Week 1**
- How many sales have they done?
- Any confusion with the interface?
- Did they encounter any errors or crashes?
- Action: Fix any blocking issue within 24 hours.

**Week 2**
- Are they using it consistently or going back to old system? Why?
- What feature do they wish it had?
- Any positive moments ("this saved me time when...")?

**Week 3**
- Are staff members using it or just the owner?
- Any data they want to see that they can't find?
- Invite them to share with another shop owner if happy.

**Week 4**
- Summary of how it is going overall
- Introduce pricing — explain what they will pay after the 3-month free period
- Ask: "Would you recommend this to another shop owner?" (If yes, ask for an introduction.)

---

## Feedback Collection

### During the beta period
- Ask for feedback proactively, not just when something goes wrong
- Every bug report is a gift — thank them and fix it quickly
- Keep a running list of all feedback in a simple spreadsheet: date, customer, issue/suggestion, status

### Feedback categories
| Category | Action |
|----------|--------|
| Critical bug (can't complete a sale) | Fix within 24 hours |
| Non-critical bug (cosmetic, rare) | Fix within 1 week |
| Feature request (core workflow) | Prioritise for Month 2 |
| Feature request (nice to have) | Add to backlog |
| Confusion / UX issue | Fix copy or add tooltip within 1 week |

---

## Graduation Criteria

Move a beta customer from free beta to paying customer when all three conditions are met:

1. **Usage** — Using the POS as their primary sales system for at least 2 consecutive weeks without you needing to intervene
2. **Stability** — No critical bugs reported in the past 7 days
3. **Intent** — Has expressed willingness to pay ("yes, I would keep using this if I had to pay")

### Graduation conversation
> "You've been using it for [X weeks] now and it seems to be working well for you. Our free beta period ends on [date]. After that, the plan you are on is PKR [price]/month. I wanted to give you plenty of notice so you can decide. Is there anything I can do to make sure it keeps working well for your shop?"

---

## Common Objections and Responses

| Objection | Response |
|-----------|----------|
| "I am not tech-savvy" | "That's exactly why I want to show you — it is designed to be simpler than most apps. One screen for selling." |
| "What if the internet goes down?" | "It works 100% offline. Your sales still go through and sync later." |
| "What if you shut down your company?" | "All your data can be exported as Excel files any time. You are never locked in." |
| "My current system is free (Excel/paper)" | "What does it cost you to count stock manually every week? This saves that time." |
| "I need it in Urdu" | "Urdu language support is on the roadmap. For now the key buttons are simple enough to use." |

---

## Reference Card Template

Print or screenshot this and give to beta customers.

```
POS Quick Reference — [Shop Name]

LOGIN: https://app.yourdomain.com
USERNAME: [their email]

TO MAKE A SALE:
1. Tap products to add them to the cart
2. Tap "Checkout"
3. Choose payment method (Cash / Card / JazzCash)
4. Enter amount received → tap "Complete Sale"

TO ADD A PRODUCT:
Inventory → Products → Add Product

TO VIEW TODAY'S SALES:
Reports → Daily Sales → Today

SUPPORT: WhatsApp [your number]
```

---

## Beta Program Timeline

| Week | Milestone |
|------|-----------|
| Week 0 | Recruit 3 beta customers, complete onboarding sessions |
| Week 1–2 | Daily monitoring of errors in Sentry, fix critical bugs |
| Week 3–4 | Weekly check-ins, collect structured feedback |
| Week 5–6 | Address top feedback items, prepare graduation conversations |
| Week 7–8 | Convert beta customers to paid, use feedback in launch announcement |
| Week 8 | Public launch |
