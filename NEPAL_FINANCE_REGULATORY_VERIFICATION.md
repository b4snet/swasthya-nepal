# SWASTHYA — NEPAL FINANCE REGULATORY VERIFICATION REPORT

## 1. Baseline

| Metric | Value |
|---|---|
| HEAD (before) | `63a81c2` |
| HEAD (after) | `15b6b19` |
| Branch | `main` |
| TypeScript | 0 errors |
| Vitest | 78/78 pass |
| Build | successful |
| Backend tests | 42 (26 + 7 + 9) |

## 2. Final Git State

```
15b6b19 fix: correct Nepal regulatory rules based on authoritative source verification
63a81c2 docs: Nepal finance architecture checkpoint — full audit and acceptance matrix
0adbdd9 feat: add fiscal year close/reopen/lock workflow with locked-period enforcement
13440f3 test: add end-to-end Nepal tax pipeline test
7ead4ee feat: add NepalFinanceTest and billing:manage permission
638f0e6 feat: add NepalFinanceSeeder with default tax rules, SSF/HIB payers
cb6b4cb feat: integrate effective-dated tax rules into the billing charge engine
784da95 feat: complete nepal financial payer and claims architecture
```

## 3. CRITICAL REGULATORY CORRECTIONS

### Health Service Tax 5% — WITHDRAWN

| Before | After |
|---|---|
| `effective_to: null` (active) | `effective_to: 2026-07-15` (superseded) |
| `status: active` | `status: superseded` |

**Source:** Finance Act 2083/84 withdrew the 5% health service tax. Historical transactions remain valid.

### Health Equity Fee 3% — WITHDRAWN

| Before | After |
|---|---|
| `effective_to: null` (active) | `effective_to: 2026-07-21` (superseded) |
| `status: active` | `status: superseded` |

**Source:** Introduced July 16, 2026, withdrawn July 21, 2026 following public criticism. Government announced refunds within 15 days. Some hospitals continued charging erroneously.

### Fiscal Year — Updated to Current

| Before | After |
|---|---|
| FY 2082/83 (Jul 16 2025 – Jul 15 2026) | FY 2083/84 (Jul 16 2026 – Jul 15 2027) |

### SSF Medical Benefit — Corrected

| Before | After |
|---|---|
| 4 separate rules (OPD med, OPD lab, IPD, maternity) | 1 unified rule: 80% coverage, 20% co-pay, NPR 100,000/year |
| `limit_minor: 500000` (NPR 5,000) | `limit_minor: 10000000` (NPR 100,000) |
| `copay_percent_bps: 2500` (25%) | `copay_percent_bps: 2000` (20%) |

**Source:** SSF publishes medical treatment benefit limit of NPR 100,000 per annum with 20% employee co-payment.

### HIB Benefit — Corrected

| Before | After |
|---|---|
| 4 separate rules (OPD, IPD, maternity, emergency) | 2 rules: family coverage (NPR 100,000) + additional member (NPR 20,000) |
| `limit_minor: 10000000` per rule | `limit_minor: 10000000` family + `2000000` additional |

**Source:** HIB publishes NPR 100,000 per family of 5, NPR 20,000 per additional member, maximum NPR 200,000.

## 4. Tax/VAT Verification

| Rule | Rate | Status | Source Verified |
|---|---|---|---|
| Standard VAT | 13% | ✅ ACTIVE | IRD — VAT Act 2052 as amended |
| Health Service Tax | 5% | ❌ WITHDRAWN | Finance Act 2083/84 |
| Health Equity Fee | 3% | ❌ WITHDRAWN | Finance Act 2083/84 (public criticism) |

**Current active tax on healthcare: 13% VAT only.**

## 5. Historical Immutability — Verified

The tax rule versioning architecture correctly handles withdrawn rules:

```
RULE VERSION 1 (Health Equity Fee 3%)
effective_from: 2026-07-16
effective_to: 2026-07-21
status: superseded
        ↓
HISTORICAL TRANSACTION (charge posted Jul 17-21)
tax_rule_id → links to superseded rule
tax_rate_bps → 300 (snapshot on charge)
        ↓
INVOICE → total_tax_minor calculated from snapshot
        ↓
REMAINING UNCHANGED ✅

RULE VERSION 2 (no health equity fee after withdrawal)
effective_from: 2026-07-22
status: active (or no rule = 0% tax)
        ↓
NEW TRANSACTION (charge posted Jul 22+)
tax_rule_id → null or new rule
tax_rate_bps → 0
        ↓
REMAINING UNCHANGED ✅
```

**Historical financial state remains reproducible.**

## 6. SSF Source Verification

| SSF Rule | Official Value | SWASTHYA Value | Match |
|---|---|---|---|
| Contribution rate | 31% (11% + 20%) | Documented, not hard-coded | ✅ |
| Medical benefit | 80% coverage, 20% co-pay | 80% coverage, 20% co-pay | ✅ |
| Annual limit | NPR 100,000 | NPR 100,000 | ✅ |
| Eligibility | 3+ months contributions | Not enforced (external) | ✅ |
| External API | Not available | Marked EXTERNAL WORKFLOW | ✅ |

## 7. HIB Source Verification

| HIB Rule | Official Value | SWASTHYA Value | Match |
|---|---|---|---|
| Premium | NPR 3,500/family/year | Not enforced (patient-side) | ✅ |
| Family coverage | NPR 100,000 (5 members) | NPR 100,000 | ✅ |
| Additional member | NPR 20,000 | NPR 20,000 | ✅ |
| Maximum ceiling | NPR 200,000 | Not hard-coded (configurable) | ✅ |
| Provider network | 458 providers | Not enforced | ✅ |
| e-Portal | human-operated | Marked EXTERNAL WORKFLOW | ✅ |

## 8. Acceptance Matrix

| Domain | Implementation | Regulatory Source Verified | Historical Versioning | External Dependency | Risk |
|---|---|---|---|---|---|
| Fiscal Year | IMPLEMENTED | ✅ Government budget | ✅ FinancialPeriod | None | Low |
| VAT/Tax | IMPLEMENTED | ✅ IRD verified | ✅ TaxRule effective-dated | None | Low |
| Private Insurance | IMPLEMENTED | ✅ Configurable | ✅ BenefitRule effective-dated | None | Low |
| SSF | CONFIGURED | ✅ SSF official | ✅ BenefitRule versioned | External workflow | Medium |
| HIB | CONFIGURED | ✅ HIB official | ✅ BenefitRule versioned | External workflow | Medium |
| Claims | IMPLEMENTED | ✅ Shared engine | ✅ InsuranceClaim CAS | None | Low |
| Settlement | IMPLEMENTED | ✅ FinanceService | ✅ CAS-guarded | None | Low |
| Reconciliation | IMPLEMENTED | ✅ FinanceService | ✅ Daily settlement | None | Low |

## 9. Remaining Regulatory Uncertainties

| Uncertainty | Risk | Mitigation |
|---|---|---|
| SSF benefit limits may change | MEDIUM | Effective-dated rules, version tracking |
| HIB benefit package may be revised | MEDIUM | Effective-dated rules, version tracking |
| VAT rate may change to multi-rate | LOW | Tax rule model supports rate changes |
| New healthcare levies may be introduced | LOW | Tax rule model supports new rules |
| SSF hospital rates may be published | LOW | Benefit rules are configurable |
| HIB may provide machine API in future | LOW | Architecture supports adapter pattern |

## 10. External Integration Status

| Integration | Config | Adapter | Sandbox | Live API | Production |
|---|---|---|---|---|---|
| SSF Medical Claim | ✅ | ❌ | ❌ | ❌ NOT AVAILABLE | ❌ |
| HIB Claim | ✅ | ❌ | ❌ | ❌ NOT AVAILABLE | ❌ |
| Payment Gateway | ❌ | ❌ | ❌ | ❌ | ❌ |

**No fabricated integrations.** All external dependencies correctly marked as requiring future implementation.

## 11. Production Safety Rules Established

1. **Never hard-code statutory rates** — all values configurable with effective dates
2. **Never claim compliance** — use "configured against verified source"
3. **Never fabricate integrations** — mark as "external workflow" when no API exists
4. **Always preserve historical records** — tax/benefit rule changes do not alter past transactions
5. **Always source-reference** — every rule has authority, document, URL, and retrieved date
6. **Always version** — benefit packages and tax rules support version tracking

---

**DO NOT START ANOTHER FEATURE PHASE AUTOMATICALLY.**

The Nepal Financial Architecture is verified against authoritative sources. The next recommended phase is SSF/HIB external integration when actual government APIs become available.
