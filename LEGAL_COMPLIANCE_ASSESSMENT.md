# LEGAL_COMPLIANCE_ASSESSMENT.md — Swasthya Legal & Compliance Position

> **Status:** Phase 22 (National Scale) deliverable · **Owner:** Principal Architect
> **Honesty principle:** This document records what is **verified** (with
> evidence) and what is **NOT PROVEN** (requires legal review). It makes
> **no compliance claim**. Swasthya will not claim compliance with any law,
> regulation, or standard until (a) a qualified legal assessment has been
> performed against the *then-current* law and (b) the controls the
> assessment relies on are verified in the deployment environment.
> `PRODUCT_REQUIREMENTS.md` §9 (no claim without proof) governs here.

---

## 1. Purpose and scope

Phase 22 requires *"legal assessment → verified compliance claims (only
then)."* This document is the assessment **register**: it separates the
technical controls the platform can prove today from the legal
determinations that only counsel can make. It is deliberately not a legal
opinion.

Scope: Nepal and the jurisdictions in which Swasthya operates (initially
Nepal; expansion per `PRODUCT_REQUIREMENTS.md` §7). At the time of writing
no legal assessment has been commissioned; this register is the input to
that engagement.

---

## 2. Verified technical controls (evidence-backed)

These controls are implemented and proven by the repository's test suites
and drill evidence — they are the *technical foundation* a compliance
assessment would rely on, not a compliance claim in themselves.

| Control | Evidence |
|---|---|
| Tenant/facility/branch isolation at the database layer (RLS, FORCE RLS, `swasthya_app` NOBYPASSRLS) | `ClaimsBasedRlsTest`, `TenancyDatabaseInventoryTest`, restore-drill isolation probes (1/0/0) |
| Claims are server-derived; no client-supplied tenant/facility/role input | `ResolveTenantContext`, `AuthClaims` (TENANCY.md §7) |
| Consent capture + purpose limitation for portal/telehealth/partner access | `Consent` model; `PatientPortalTest`, `TelehealthTest`, `InteroperabilityTest` |
| PHI-safe audit trail (facts + ids only; hash-chained) | `AuditLogger`; audit-payload tests across all slices |
| PHI-safe logging (no passwords, tokens, MFA secrets, patient identifiers in logs) | Phase 2 security suites; `SECURITY_AUDIT.md` |
| Data retention classes defined (clinical/financial longer, operational shorter) | `DATABASE.md` §4 |
| Backup encryption/DR design + measured restore drill | `DISASTER_RECOVERY.md`; `NATIONAL_SCALE.md` §2 |
| MFA for staff; recovery codes hashed at rest; no bypass path | Phase 2 MFA suites; `SECURITY_AUDIT.md` |
| Explicit recording policy + consent for telehealth; no implicit recording | `TelehealthService`; `TelehealthTest` |
| Export/portal access audited and consent-bound | `PayrollExport`/`PatientPortal` suites |

---

## 3. Legal questions requiring review (NOT PROVEN)

These are questions only a qualified legal assessment can answer. **No
answer is assumed** in this document, and no compliance claim is made on
any of them:

1. **Nepal Privacy Act, 2075 (2018)** and its implementing rules —
   applicability, obligations, and penalties for a health-data SaaS
   processor/controller; whether the platform is controller, processor, or
   both for each data flow.
2. **Health-data specific rules** — any health-information privacy
   requirements beyond the general privacy act (e.g., clinical record
   retention, patient access rights, secondary-use restrictions).
3. **Cross-border data transfer** — hosting/data-residency requirements
   and restrictions on storing Nepali patient data outside Nepal; the
   current architecture's region placement must be assessed against the
   then-current rules.
4. **Consent law** — sufficiency of the platform's consent capture for
   treatment, telehealth (including recording), research/secondary use,
   and patient-portal/partner access; parental/guardian consent for minors.
5. **Retention and deletion** — whether the retention classes in
   `DATABASE.md` §4 satisfy statutory retention for medical records, and
   the lawful deletion process at subscription end (`PRODUCT_REQUIREMENTS`
   §7.5).
6. **Employment/staff data** — HR module (Phase 15) staff personal data,
   payroll exports, and biometric/RFID readiness against applicable
   employment and data-protection law.
7. **National ID / NPRN linkage** — the product references optional
   national-ID linkage with consent (§6.4); legality and data-sharing
   terms require assessment before activation.
8. **National integrations** — when a real national system is specified,
   the data-sharing agreement, liability allocation, and consent terms for
   each integration must be assessed *before* go-live.
9. **Audit/subpoena/regulatory access** — legal obligations to provide
   records to regulators and law enforcement, and the mechanism for doing
   so without breaking isolation guarantees.
10. **SLA/contract posture** — SaaS subscription terms, limitation of
    liability, data-processing agreements with hospitals, and the
    hospital-as-controller/platform-as-processor split.

---

## 4. What happens before any compliance claim

The roadmap's rule (also `MASTER_RULES.md` §39) is enforced as a gate:

1. Commission a qualified legal assessment against the then-current law
   (this register is the input).
2. Map each legal requirement to a specific control with evidence.
3. Fix any control gap the assessment identifies.
4. Record the assessment, the mapping, and any residual risk in this
   document with a **verified** date and byline.
5. Only then may marketing/sales material state compliance — and only for
   the specific laws assessed.

Until then, every statement in sales and product material must read:
**"the platform is designed for compliance-ready operation; no compliance
claim is made pending legal assessment."**

---

## 5. Register of status

| Item | Status |
|---|---|
| Technical controls register | **VERIFIED** (evidence in §2) |
| Legal assessment engagement | **NOT STARTED** |
| Compliance claims | **NONE** |
| National integrations | **NOT PRESENT** (none specified) |
| Data-residency decision | **NOT PROVEN** (deployment-phase) |
