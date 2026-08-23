# SWASTHYA — NEPAL FINANCE END-TO-END CHECKPOINT

## 1. Baseline

| Metric | Value |
|---|---|
| HEAD (before) | `54cda0f` |
| HEAD (after) | `54f78f5` |
| Branch | `main` |
| TypeScript | 0 errors |
| Vitest | 78/78 pass (1 pre-existing flaky error in AdminRolesPage) |
| Build | successful |
| Backend tests | 54 (26 + 7 + 9 + 12 new E2E) |

## 2. What Was Proven

### Self-Pay Flow ✅
```
Patient → Encounter → Charge (tax auto-resolved) → Invoice (tax calculated) → Payment (settled)
```
- Charge: NPR 500, tax_rule_id linked, tax_rate_bps = 1300
- Invoice: total_tax_minor = 6500 (50000 × 1300 / 10000)
- Payment: full amount settles invoice

### Private Insurance Flow ✅
```
Patient → Payer → Policy → Benefit (75/25) → Charge → Coverage calc → Invoice → Patient share
```
- Coverage: 75% of charge = payer share
- Patient responsibility: 25% of charge
- Invariant: coverage + patient responsibility = total charge

### SSF Flow ✅
```
Patient → SSF Payer → Benefit (80/20, NPR 100K limit) → Charge → Claim → Patient share
```
- SSF covers 80%, patient pays 20% co-payment
- Annual limit: NPR 100,000 verified
- Claim created with claim_type = 'ssf', benefit_rule_id linked
- External submission: EXTERNAL WORKFLOW (no live API)

### HIB Flow ✅
```
Patient → HIB Payer → Benefit (100% up to NPR 100K) → Charge → Claim → Capped coverage
```
- HIB covers 100% up to NPR 100,000 family limit
- Exceeding limit: patient pays remainder
- Claim created with claim_type = 'hib', benefit_rule_id linked
- External submission: EXTERNAL WORKFLOW (no live API)

### Corporate Sponsor Flow ✅
```
Patient → Sponsor → OPD benefit (100%) + Pharmacy (excluded) → Selective coverage
```
- Service-category-specific benefits work correctly
- Excluded services: patient pays 100%

### Tax Rule Versioning ✅
```
Rule V1 (13%) → Historical charge → Invoice with V1 tax
Rule V2 (15%) → New charge → Invoice with V2 tax
V1 invoice unchanged after V2 introduction
```
- Historical immutability proven
- Old charges retain tax_rule_id + tax_rate_bps snapshot

### Fiscal Period Enforcement ✅
```
Locked period → Charge::resolveTaxFields() → ApiException thrown
Open period → Charge::resolveTaxFields() → succeeds
No period → Charge::resolveTaxFields() → succeeds (periods optional)
```

### Refund Lifecycle ✅
```
Charge → Invoice → Payment → Refund Request → Approve → Complete
```
- Segregation of duties: requester ≠ approver
- Refundable amount correctly calculated
- Over-refund prevented

### Claim Lifecycle ✅
```
Draft → Submitted → Pending → Paid (with settlement)
```
- CAS-guarded transitions
- Settlement amount validated against billed total

### Financial Invariants ✅
- No over-refund (tested and rejected)
- Coverage + patient responsibility = total charge
- Payment + outstanding = invoice total
- Settlement ≤ billed total

## 3. Test Coverage Summary

| Test File | Tests | Purpose |
|---|---|---|
| NepalFinanceTest | 26 | CRUD, auth, RBAC, tenant isolation |
| NepalTaxPipelineTest | 7 | Tax resolution, invoice, receipt |
| FiscalPeriodWorkflowTest | 9 | Close/reopen/lock lifecycle |
| NepalFinanceE2ETest | 12 | Full financial flows |
| **Total** | **54** | |

## 4. What Is NOT Fabricated

| Claim | Status |
|---|---|
| SSF live integration | ❌ NOT AVAILABLE — external workflow |
| HIB live integration | ❌ NOT AVAILABLE — external workflow |
| Payment gateway | ❌ NOT IMPLEMENTED |
| Nepal tax compliance | ⚠️ CONFIGURED against verified source |
| SSF compliance | ⚠️ CONFIGURED against verified source |
| HIB compliance | ⚠️ CONFIGURED against verified source |

## 5. Architecture Verified

```
ONE PATIENT ✅
ONE FINANCIAL ENGINE ✅ (BillingService + FinanceService)
ONE CLAIM ENGINE ✅ (InsuranceClaim with shared lifecycle)
MULTIPLE PAYER CONFIGURATIONS ✅ (SSF, HIB, private, corporate, self-pay)
VERSIONED RULES ✅ (TaxRule + BenefitRule with effective dates)
HISTORICAL IMMUTABILITY ✅ (tax_rule_id snapshot on charges)
RLS ✅ (tenant + facility isolation)
RBAC ✅ (billing:manage, billing:view permission gates)
AUDIT ✅ (all financial events logged)
```

## 6. Remaining Gaps

| Gap | Priority | Reason |
|---|---|---|
| SSF external adapter | P2 | No public API exists |
| HIB external adapter | P2 | HIB uses external IMIS portal |
| Payment gateway | P3 | No provider contract |
| TDS in accounts payable | P3 | Not applicable to patient billing |

## 7. Files Changed This Phase

| File | Change |
|---|---|
| `NepalFinanceSeeder.php` | Corrected withdrawn taxes, updated SSF/HIB rules |
| `NepalFinanceE2ETest.php` | NEW — 12 comprehensive E2E tests |
| `NEPAL_REGULATORY_SOURCE_REGISTER.md` | NEW — verified authoritative sources |
| `NEPAL_FINANCE_REGULATORY_VERIFICATION.md` | NEW — verification report |

---

**DO NOT START ANOTHER FEATURE PHASE AUTOMATICALLY.**

The Nepal Financial Architecture is CONFIGURED, CONNECTED, TESTED, AUDITABLE, and HISTORICALLY SAFE. The next recommended phase is SSF/HIB external integration when actual government APIs become available.
