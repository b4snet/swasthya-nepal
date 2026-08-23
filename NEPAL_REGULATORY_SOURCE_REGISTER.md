# NEPAL FINANCIAL REGULATORY SOURCE REGISTER

Generated: August 23, 2026
Baseline: `63a81c2` → pending commit

---

## 1. TAX / VAT

### Standard VAT — 13%

| Field | Value |
|---|---|
| Rule | Standard VAT on goods and services |
| Rate | 13% (1300 basis points) |
| Authority | Inland Revenue Department, Nepal |
| Source Document | VAT Act 2052 (1996), as amended |
| Source URL | https://ird.gov.np |
| Publication Date | Ongoing (Act + amendments) |
| Effective Date | Ongoing |
| Version | Current as of August 2026 |
| Retrieved Date | August 23, 2026 |
| Implementation Status | IMPLEMENTED — configurable, effective-dated |
| Last Verified | August 23, 2026 |
| Notes | Single standard rate of 13% on most goods and services. Multi-rate system being considered but not yet implemented. |

### Health Service Tax — 5% (WITHDRAWN)

| Field | Value |
|---|---|
| Rule | Health service tax on private healthcare |
| Rate | 5% (500 basis points) |
| Authority | Ministry of Finance, Nepal |
| Source Document | Finance Act 2082/83 |
| Source URL | https://mof.gov.np |
| Publication Date | May 2025 |
| Effective Date | 2025-07-16 |
| End Date | 2026-07-15 (withdrawn in FY 2083/84 budget) |
| Version | withdrawn |
| Retrieved Date | August 23, 2026 |
| Implementation Status | SUPERSEDED — archived for historical transaction reproducibility |
| Last Verified | August 23, 2026 |
| Notes | Withdrawn in FY 2083/84 budget. Historical charges under this rule remain valid. |

### Health Equity Fee — 3% (WITHDRAWN)

| Field | Value |
|---|---|
| Rule | Health equity fee on private healthcare |
| Rate | 3% (300 basis points) |
| Authority | Ministry of Finance, Nepal |
| Source Document | Finance Act 2083/84 |
| Source URL | https://mof.gov.np |
| Publication Date | May 29, 2026 |
| Effective Date | 2026-07-16 |
| End Date | 2026-07-21 (withdrawn 5 days after introduction) |
| Version | withdrawn |
| Retrieved Date | August 23, 2026 |
| Implementation Status | SUPERSEDED — refunds being processed |
| Last Verified | August 23, 2026 |
| Notes | Introduced then withdrawn following widespread public criticism. Government announced refunds within 15 days. Some hospitals continued charging. |

---

## 2. FISCAL YEAR

| Field | Value |
|---|---|
| Rule | Nepal fiscal year runs mid-Shrawan to mid-Shrawan |
| Authority | Government of Nepal, Ministry of Finance |
| Source Document | Budget Speech FY 2083/84 |
| Source URL | https://mof.gov.np/content/1741/budget8384/ |
| Current FY | 2083/84 (July 16, 2026 – July 15, 2027) |
| BS Dates | Shrawan 1 to Chaitra 30/31 |
| Retrieved Date | August 23, 2026 |
| Implementation Status | IMPLEMENTED — FinancialPeriod model with calendar_type, nepal_fiscal_year |
| Last Verified | August 23, 2026 |

---

## 3. SOCIAL SECURITY FUND (SSF)

### Contribution Rate

| Field | Value |
|---|---|
| Rule | Mandatory contribution-based social security |
| Total Rate | 31% of basic salary |
| Employee Share | 11% |
| Employer Share | 20% |
| Authority | Social Security Fund, Nepal |
| Source Document | Social Security Act 2074 (2017), SSF Rules |
| Source URL | https://www.ssf.gov.np |
| Legal Reference | Section 60 + Regulations |
| Retrieved Date | August 23, 2026 |
| Implementation Status | CONTRIBUTION RATE DOCUMENTED — hospital does not control contributions |
| Last Verified | August 23, 2026 |
| Notes | SSF is an employer-employee contribution system. The hospital's role is to facilitate employee registration and verify eligibility. |

### Medical Treatment Benefit

| Field | Value |
|---|---|
| Rule | Medical treatment, health and maternity protection scheme |
| Coverage | 80% of approved bill (20% employee co-payment) |
| Annual Limit | NPR 100,000 per annum per contributor |
| Eligibility | Minimum 3 months of contributions |
| Authority | Social Security Fund, Nepal |
| Source Document | SSF Medical Treatment, Health and Maternity Protection Scheme |
| Source URL | https://www.ssf.gov.np |
| Retrieved Date | August 23, 2026 |
| Implementation Status | CONFIGURED as SSF payer with benefit rules |
| Last Verified | August 23, 2026 |
| Notes | Hospital bills are submitted through SSF-affiliated hospital workflow. Claims are processed by SSF, not directly by the hospital. |

### Hospital Rate Information

| Field | Value |
|---|---|
| Rule | SSF publishes hospital treatment rate information |
| Authority | Social Security Fund, Nepal |
| Source Document | SSF Hospital Rate List |
| Source URL | https://www.ssf.gov.np |
| Implementation Status | EXTERNAL — rate data must be configured manually or imported when available |
| Notes | SSF publishes approved rates for affiliated hospitals. SWASTHYA should support importing or configuring these rates but does not currently have an authorized machine interface. |

---

## 4. HEALTH INSURANCE BOARD (HIB)

### Benefit Package

| Field | Value |
|---|---|
| Rule | National health insurance benefit package |
| Premium | NPR 3,500 per family per year |
| Family Size | Up to 5 members |
| Base Coverage | NPR 100,000 per year per family |
| Additional Members | NPR 20,000 per additional member beyond 5 |
| Maximum Ceiling | NPR 200,000 per family |
| Authority | Health Insurance Board, Nepal |
| Source Document | HIB Benefit Package |
| Source URL | https://hib.gov.np |
| Provider Network | 458 providers (91% public) |
| Medicines Covered | 1,133 types |
| Retrieved Date | August 23, 2026 |
| Implementation Status | CONFIGURED as HIB payer with benefit rules |
| Last Verified | August 23, 2026 |

### HIB e-Portal

| Field | Value |
|---|---|
| Rule | Online enrollment and renewal |
| Authority | Health Insurance Board, Nepal |
| Source URL | https://hib.gov.np |
| Implementation Status | EXTERNAL — human-operated portal, no authorized machine API |
| Notes | HIB uses openIMIS for claims processing. Hospital claims are submitted through the HIB portal or IMIS interface. SWASTHYA should support export/preparation of claim data but does not have direct API integration. |

### Claim Process

| Field | Value |
|---|---|
| Rule | Claims submitted through HIB e-portal or IMIS |
| Authority | Health Insurance Board, Nepal |
| Implementation Status | EXTERNAL WORKFLOW — SWASTHYA prepares claims, external submission required |
| Notes | No authorized machine API exists for direct claim submission. Hospital staff manually submit claims through the HIB portal. |

---

## 5. EXTERNAL INTEGRATION STATUS

| Integration | Internal Config | Export/Adapter | Sandbox | Live API | Production |
|---|---|---|---|---|---|
| SSF Contribution | DOCUMENTED | N/A (employer-side) | N/A | N/A | N/A |
| SSF Medical Claim | CONFIGURED | NOT IMPLEMENTED | NOT TESTED | NOT AVAILABLE | NOT AVAILABLE |
| SSF Hospital Rate | NOT IMPLEMENTED | NOT IMPLEMENTED | NOT TESTED | NOT AVAILABLE | NOT AVAILABLE |
| HIB Enrollment | NOT APPLICABLE | N/A (patient-side) | N/A | N/A | N/A |
| HIB Claim | CONFIGURED | NOT IMPLEMENTED | NOT TESTED | NOT AVAILABLE | NOT AVAILABLE |
| HIB e-Portal | NOT APPLICABLE | N/A (human portal) | N/A | N/A | N/A |
| Payment Gateway | NOT IMPLEMENTED | NOT IMPLEMENTED | NOT TESTED | NOT AVAILABLE | NOT AVAILABLE |

---

## 6. REMAINING REGULATORY UNCERTAINTIES

| Uncertainty | Risk | Mitigation |
|---|---|---|
| SSF benefit limits may change | MEDIUM | Effective-dated rules, version tracking |
| HIB benefit package may be revised | MEDIUM | Effective-dated rules, version tracking |
| VAT rate may change to multi-rate | LOW | Tax rule model supports rate changes |
| New healthcare levies may be introduced | LOW | Tax rule model supports new rules |
| SSF hospital rates may be published | LOW | Benefit rules are configurable |
| HIB may provide machine API in future | LOW | Architecture supports adapter pattern |

---

## 7. PRODUCTION SAFETY RULES

1. **Never hard-code statutory rates** — all values must be configurable with effective dates
2. **Never claim compliance** — use "configured against verified source"
3. **Never fabricate integrations** — mark as "external workflow" when no API exists
4. **Always preserve historical records** — tax/benefit rule changes must not alter past transactions
5. **Always source-reference** — every rule must have authority, document, URL, and retrieved date
6. **Always version** — benefit packages and tax rules must support version tracking
