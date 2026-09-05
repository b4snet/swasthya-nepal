# SWASTHYA — Clinical Change Governance

**Version:** 1.0
**Date:** 2026-09-04
**Status:** DRAFT (requires organizational adoption)

---

## 1. Governance Roles

| Role | Responsibility | Assigned |
|---|---|---|
| Clinical Safety Owner | Accountable for clinical safety decisions; approves safety-critical changes | NOT ASSIGNED |
| Product Owner | Prioritizes features; accepts clinical workflow changes | NOT ASSIGNED |
| Engineering Owner | Technical implementation; code quality; test coverage | NOT ASSIGNED |
| Security Owner | Authorization, RLS, PHI safety, penetration testing | NOT ASSIGNED |
| QA Owner | Test strategy; regression; UAT coordination | NOT ASSIGNED |
| Clinical Reviewer | Validates clinical workflow is safe and appropriate | NOT ASSIGNED |
| Change Approver | Reviews and approves clinical logic changes | NOT ASSIGNED |
| Incident Owner | Manages clinical safety incidents | NOT ASSIGNED |

---

## 2. Change Classification

### Tier 1: Clinical Safety Critical

Requires: Clinical Safety Owner + Engineering Owner + Security Owner approval

Examples:
- Changes to signing behavior
- Changes to immutability enforcement
- Changes to clinical state transitions
- Changes to RLS policies on clinical tables
- Changes to authorization middleware
- Changes to PHI logging rules

### Tier 2: Clinical Workflow

Requires: Product Owner + Clinical Reviewer approval

Examples:
- New clinical documentation fields
- Changes to note templates
- Changes to order set structure
- Changes to clinical alerts
- Changes to result display

### Tier 3: Technical

Requires: Engineering Owner approval

Examples:
- Performance optimizations
- Refactoring without behavior change
- Test additions
- Documentation updates

---

## 3. Change Control Process

### For Tier 1 Changes

1. **Proposal**: Document the change, rationale, and safety impact
2. **Review**: Clinical Safety Owner + Security Owner review
3. **Implementation**: Engineering implements with full test coverage
4. **Verification**: QA runs regression gate
5. **Clinical Review**: Clinical Reviewer validates workflow safety
6. **Approval**: All required approvers sign off
7. **Release**: Deployed with rollback plan
8. **Monitoring**: Post-release safety monitoring

### For Tier 2 Changes

1. **Proposal**: Document the change and clinical rationale
2. **Review**: Product Owner + Clinical Reviewer review
3. **Implementation**: Engineering implements
4. **Verification**: QA runs regression gate
5. **Approval**: Required approvers sign off
6. **Release**: Deployed

### For Tier 3 Changes

1. **Proposal**: Document the technical change
2. **Review**: Engineering Owner reviews
3. **Implementation**: Developer implements
4. **Verification**: Automated tests pass
5. **Release**: Deployed

---

## 4. Versioning Requirements

| Artifact | Versioning Required | Format |
|---|---|---|
| Order Sets | YES | Semantic versioning |
| Clinical Templates | YES | Semantic versioning |
| Clinical Pathways | YES | Semantic versioning |
| Decision Support Rules | YES | Semantic versioning |
| RLS Policies | YES | Migration-based |
| Authorization Rules | YES | Seeder-based with version tracking |

---

## 5. Rollback Requirements

| Change Type | Rollback Required | Method |
|---|---|---|
| Database migration | YES | Reverse migration |
| Clinical logic | YES | Code rollback |
| Order set changes | YES | Version rollback to previous |
| RLS policy changes | YES | Reverse migration |
| Authorization changes | YES | Seeder rollback |

---

## 6. Post-Release Monitoring

After any Tier 1 or Tier 2 clinical change:

- Monitor error rates for 24 hours
- Review audit logs for anomalies
- Check for increased 409 conflicts (concurrency issues)
- Verify RLS policies are functioning
- Confirm no PHI exposure in new code paths

---

## 7. Incident Response Process

### Incident Classification

| Severity | Description | Response Time |
|---|---|---|
| Critical | Patient safety risk; data breach; signed record modified | Immediate |
| High | Unauthorized access; PHI exposure; workflow broken | 4 hours |
| Medium | Usability issue; performance degradation | 24 hours |
| Low | Cosmetic issue; enhancement request | Next sprint |

### Incident Workflow

1. **Detect**: Automated monitoring or user report
2. **Contain**: Disable affected feature if necessary
3. **Investigate**: Root cause analysis
4. **Remediate**: Fix the issue
5. **Verify**: Confirm fix works
6. **Document**: Record in incident log
7. **Communicate**: Notify affected stakeholders
8. **Close**: Formal closure with lessons learned

---

## 8. Document Control

| Document | Owner | Review Cycle | Version |
|---|---|---|---|
| CLINICAL_SAFETY.md | Clinical Safety Owner | Quarterly | 1.0 |
| CLINICAL_HAZARD_REGISTER.md | Clinical Safety Owner | Quarterly | 1.0 |
| CLINICAL_SAFETY_REQUIREMENTS.md | Clinical Safety Owner | Quarterly | 1.0 |
| CLINICAL_SAFETY_EVIDENCE_MATRIX.md | QA Owner | Per release | 1.0 |
| CLINICAL_UAT_MATRIX.md | QA Owner | Per release | 1.0 |
| CLINICAL_CHANGE_GOVERNANCE.md | Clinical Safety Owner | Annually | 1.0 |

---

## Adoption Status

| Item | Status |
|---|---|
| Governance document created | YES (this file) |
| Roles assigned | NO |
| Process adopted | NO |
| First formal review conducted | NO |
| External assessment scheduled | NO |
