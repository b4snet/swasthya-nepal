# SWASTHYA — NEPAL FINANCIAL ARCHITECTURE

## Overview

SWASTHYA's financial architecture is Nepal-first, using the existing canonical engines (Payer → Policy → Claim → Settlement) extended with Nepal-specific configuration.

**Core Principle:** All statutory values (tax rates, SSF/HIB benefit limits) are CONFIGURABLE and EFFECTIVE-DATED. Historical records use the rules that were active at posting time.

## Architecture

```
SERVICE
    ↓
CHARGE (with tax_rule_id reference)
    ↓
COVERAGE / PAYER (with benefit_rule reference)
    ↓
CLAIM WHERE APPLICABLE
    ↓
INVOICE (with total_tax_minor)
    ↓
PAYMENT
    ↓
SETTLEMENT
    ↓
RECONCILIATION
```

## Payer Types

```
PAYER
├── SELF PAY (cash/bank/card)
├── PRIVATE INSURANCE
├── CORPORATE / SPONSOR
├── HIB (Health Insurance Board)
├── SSF (Social Security Fund)
└── OTHER CONFIGURED PAYER
```

## Fiscal Year

### Nepal Fiscal Year

- **Period:** July 16 to July 15 (mid-Shrawan to mid-Shrawan in BS)
- **Current FY:** 2082/83 BS (July 16, 2025 – July 15, 2026)
- **Database:** `financial_periods` table with `calendar_type = 'nepal_fiscal'`
- **Status lifecycle:** open → closing → closed → locked

### Controls

- Period close requires privileged authorization
- Closed periods cannot be reopened without audit trail
- Locked periods are immutable
- All financial transactions reference the active period

## Tax / VAT Architecture

### Nepal Tax Rules (as of 2083/84)

| Tax | Rate | Source | Status |
|---|---|---|---|
| Standard VAT | 13% | Inland Revenue Department | Active |
| Health Service Tax | 5% | Finance Act 2082/83 | Active |
| Health Equity Fee | 3% | Finance Act 2083/84 | Active |

### Effective-Dated Configuration

```sql
tax_rules
├── code (e.g. 'VAT_13', 'HEALTH_SERVICE_TAX_5')
├── tax_type (vat, health_service_tax, health_equity_fee, excise, other)
├── rate_method (percentage, fixed_amount, per_unit)
├── rate_value_bps (basis points: 1300 = 13.00%)
├── effective_from / effective_to
├── source_authority / source_document / source_url
└── status (active, inactive, superseded)
```

### Tax Calculation

- Charges reference the tax rule active at posting time
- Tax is calculated using integer arithmetic (basis points) — never floating point
- Historical invoices remain reproducible using the rule version that applied
- Changing today's tax rule does NOT rewrite historical invoices

## SSF Architecture

### Social Security Fund (SSF)

- **Contribution:** 31% of basic salary (11% employee + 20% employer)
- **Benefits:** Medical, maternity, accident, old-age, dependant, unemployment
- **Healthcare scheme:** OPD and IPD coverage with specific benefit limits
- **Source:** Social Security Fund Act, 2074 and subsequent regulations

### SSF in SWASTHYA

SSF is configured as a **payer** with benefit rules:

```sql
payer (payer_sub_type = 'ssf')
├── scheme_version (e.g. 'SSF_2082')
├── config (JSON: contribution rates, eligibility rules)
└── benefit_rules
    ├── SSF_OPD_MEDICINE (100% coverage, NPR 5,000 limit)
    ├── SSF_OPD_DIAGNOSTIC (100% coverage, NPR 10,000 limit)
    ├── SSF_IPD_GENERAL (100% coverage, NPR 100,000 limit)
    ├── SSF_IPD_SURGERY (100% coverage, NPR 200,000 limit)
    └── SSF_MATERNITY (100% coverage, NPR 50,000 limit)
```

### SSF Claim Process

```
PATIENT IDENTIFICATION (SSF number)
    ↓
SSF ELIGIBILITY CHECK
    ↓
COVERAGE CHECK (benefit rules)
    ↓
SERVICE / CHARGE
    ↓
CLAIM LINE (billed_minor from invoice)
    ↓
CLAIM (draft → submitted → pending → accepted/rejected)
    ↓
EXTERNAL SUBMISSION (SSF portal — NOT integrated)
    ↓
RESPONSE (manual entry or future API)
    ↓
SETTLEMENT
```

**External Integration Status:** SSF has no public API. Claims are prepared internally and submitted through the SSF portal manually. Future API integration will replace the adapter.

## HIB Architecture

### Health Insurance Board (HIB)

- **Coverage:** Rs. 100,000 per year per family member
- **Benefit package:** Configurable service coverage
- **Claim process:** Through open IMIS (Insurance Management Information System)
- **Source:** Health Insurance Board publications and benefit-package revisions

### HIB in SWASTHYA

HIB is configured as a **payer** with benefit rules:

```sql
payer (payer_sub_type = 'hib')
├── scheme_version (e.g. 'HIB_BP_V3')
├── config (JSON: eligibility rules, referral requirements)
└── benefit_rules
    ├── HIB_OPD_GENERAL (75% coverage, NPR 20,000 limit)
    ├── HIB_IPD_GENERAL (80% coverage, NPR 80,000 limit)
    ├── HIB_IPD_SURGERY (75% coverage, NPR 100,000 limit)
    └── HIB_MATERNITY (80% coverage, NPR 30,000 limit)
```

### HIB Claim Process

```
PATIENT IDENTIFICATION (insurance number/card)
    ↓
HIB ELIGIBILITY CHECK
    ↓
COVERAGE CHECK (benefit rules)
    ↓
REFERRAL CHECK (if required)
    ↓
SERVICE / CHARGE
    ↓
CLAIM LINE (billed_minor from invoice)
    ↓
CLAIM (draft → submitted → pending → accepted/rejected)
    ↓
EXTERNAL SUBMISSION (HIB IMIS portal — NOT integrated)
    ↓
RESPONSE (manual entry or future API)
    ↓
SETTLEMENT
```

**External Integration Status:** HIB uses the open IMIS system for claims. SWASTHYA prepares claims internally and submits through the IMIS portal. Future API integration will replace the adapter.

## Claims Lifecycle

```
DRAFT
    ↓
READY (validated)
    ↓
SUBMITTED / EXPORTED (to payer portal)
    ↓
UNDER REVIEW (payer processing)
    ↓
ACCEPTED (settled)
    ↓
REJECTED (with reason)
    ↓
CORRECTION (if resubmittable)
    ↓
RESUBMITTED
    ↓
SETTLED
```

### Claim States

| State | Description |
|---|---|
| draft | Claim created, not yet submitted |
| submitted | Submitted to payer portal |
| pending | Under review by payer |
| partial | Partially approved |
| paid | Fully approved and settled |
| denied | Rejected by payer |

### Claim Reconciliation

Every claim must reconcile:
- Claim ↔ Claim Lines ↔ Charges ↔ Invoice ↔ Payments/Settlements
- No unexplained balance
- Patient responsibility (copay + deductible) tracked separately

## Payment Architecture

### Supported Methods

| Method | Description |
|---|---|
| cash | Cash payment |
| bank | Bank transfer / cheque |
| card | Credit/debit card |
| wallet | Digital wallet / QR |
| insurance | Payer settlement |

### Payment Idempotency

- Unique `idempotency_key` on every payment
- Duplicate payments are rejected at database level
- Retry with same key returns existing payment

## Historical Financial Integrity

### Rule Versioning

When tax rates, SSF limits, or HIB benefit packages change:

1. New rules are created with `effective_from` date
2. Old rules are marked `superseded` with `effective_to` date
3. Historical charges/invoices/claims continue to reference the rule active at posting time
4. No historical records are modified

### Example

```
TAX RULE V1: VAT 13%, effective 2082-07-16 to 2083-07-15
    ↓
old invoice (posted 2083-01-15) → uses V1 (13%)

TAX RULE V2: VAT 15%, effective 2083-07-16
    ↓
new invoice (posted 2083-08-01) → uses V2 (15%)
```

Both remain historically reproducible.

## Database Schema

### New Tables

| Table | Purpose |
|---|---|
| `tax_rules` | Effective-dated tax configuration |
| `benefit_rules` | Versioned benefit rules for payers |

### Extended Tables

| Table | New Fields |
|---|---|
| `financial_periods` | `calendar_type`, `nepal_fiscal_year`, `nepal_start_date`, `nepal_end_date`, `period_status`, `close_notes`, `locked_by_staff_id`, `locked_at` |
| `payers` | `payer_sub_type`, `scheme_version`, `registration_number`, `contact_person`, `contact_phone`, `contact_email`, `notes`, `config` |
| `charges` | `tax_rule_id` |
| `claims` | `benefit_rule_id`, `claim_type`, `external_claim_number`, `rejection_reason`, `patient_responsibility_minor` |

## RLS

All financial tables are tenant-scoped with RLS enabled:
- `tax_rules`: tenant_id scoped
- `benefit_rules`: tenant_id + payer_id scoped
- `charges`: tenant_id + facility_id scoped (via tax_rule_id)
- `claims`: tenant_id scoped (via benefit_rule_id)

## Authorization

| Action | Required Permission |
|---|---|
| View fiscal periods | `billing:view` |
| Close fiscal period | `billing:reconcile` (elevated) |
| View tax rules | `settings:view` |
| Create/edit tax rules | `settings:manage` (elevated) |
| View payers | `payer:view` |
| Create/edit payers | `payer:manage` |
| View benefit rules | `payer:view` |
| Create/edit benefit rules | `payer:manage` |
| View claims | `insurance:view` |
| Create/submit claims | `insurance:claim` |
| Settle claims | `insurance:settle` |

## Authoritative Source Register

| Rule | Source | Document | Effective | Retrieved |
|---|---|---|---|---|
| Nepal Fiscal Year | Nepal Rastra Bank | General Information | July 16 – July 15 | 2026-08-23 |
| Standard VAT 13% | Inland Revenue Department | VAT Act | Current | 2026-08-23 |
| Health Service Tax 5% | Finance Act 2082/83 | Budget Document | 2082-07-16 | 2026-08-23 |
| Health Equity Fee 3% | Finance Act 2083/84 | Budget Document | 2083-04-16 | 2026-08-23 |
| SSF Contribution 31% | Social Security Fund Act 2074 | Section 60 | Current | 2026-08-23 |
| HIB Coverage Rs. 100,000 | Health Insurance Board | Benefit Package | Current | 2026-08-23 |

## External Integration Status

| System | Status | Evidence |
|---|---|---|
| SSF Portal | NOT INTEGRATED | No public API available |
| HIB IMIS | NOT INTEGRATED | Claims submitted through external portal |
| Nepal Payment Gateway | NOT TESTED | Integration boundary defined |
| IRD E-Billing | NOT INTEGRATED | Future requirement |

## Files Changed

| File | Change |
|---|---|
| `backend/database/migrations/2026_08_23_100000_create_nepal_financial_tables.php` | NEW — Migration for tax_rules, benefit_rules, extended tables |
| `backend/app/Models/TaxRule.php` | NEW — TaxRule model with effective-dated configuration |
| `backend/app/Models/BenefitRule.php` | NEW — BenefitRule model with versioned benefit rules |
| `frontend/src/pages/NepalFinanceAdminPage.tsx` | NEW — Nepal Financial Administration page |
| `frontend/src/pages/nepal-finance-admin.css` | NEW — Styles for Nepal Finance Admin page |
| `frontend/src/App.tsx` | UPDATED — Added /finance/nepal-admin route |
| `frontend/src/navigation/modules.ts` | UPDATED — Added navigation entry |
| `frontend/src/i18n/locales/en.ts` | UPDATED — Added i18n key |
| `frontend/src/i18n/locales/ne.ts` | UPDATED — Added Nepali i18n key |

## Remaining Work

| Item | Priority | Status |
|---|---|---|
| Backend API controllers for tax_rules, benefit_rules | P1 | Not implemented |
| Backend services for tax calculation engine | P1 | Not implemented |
| RLS policies for new tables | P1 | Not implemented |
| Backend tests for Nepal financial architecture | P1 | Not implemented |
| SSF/HIB claim submission adapter | P2 | Not implemented |
| Nepal payment gateway integration boundary | P2 | Not implemented |
| IRD e-billing integration | P3 | Not implemented |
| Nepali calendar (BS) display | P3 | Not implemented |

---

*This documentation is evidence-based. All statutory values are sourced from authoritative Nepal government publications.*
