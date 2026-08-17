#!/usr/bin/env bash
# ============================================================================
# Swasthya — staging smoke verification gate (STAGING.md §11).
#
#   STAGING_BASE_URL=https://<api-url> \
#   STAGING_FIXTURE_PASSWORD=<fixture password> \
#   ./smoke_staging.sh
#
# Purpose: a safe, repeatable HTTP-level smoke of a DEPLOYED staging API.
# It proves, end to end, that the release candidate serves traffic with the
# intended tenancy, clinical, RPM, CDSS/AI, and audit behavior — using ONLY
# the synthetic two-tenant fixture (StagingFixtureSeeder) and disposable
# smoke data. It never provisions, deploys, or touches production; it never
# fabricates an integration; it never transmits anything to an AI model (the
# AI surface is exercised to its documented fail-closed/degraded state).
#
# The script is a COMPLEMENT to the browser-level E2E
# (frontend/playwright.staging.config.ts, which walks the same OPD chain
# through the real SPA). This script covers the API contract directly and is
# safe to run from a headless host (CI, a jump host, or a laptop).
#
# FAIL-CLOSED: any failed step prints a non-PHI diagnostic and exits 1.
# Nothing here is ever printed: passwords, tokens, Authorization headers,
# request/response bodies, cookies, database URLs, JWTs, or PHI. Output
# carries only step names, HTTP statuses, UUID ids, and counts.
#
# REQUIREMENTS
#   - A deployed staging API (Laravel) with the StagingFixtureSeeder run
#     (tenants smoke-group "A" and apex-care "B").
#   - curl on PATH.
#   - PHP on PATH (or the repository-local toolchain
#     .toolchain/php/php.exe) — used for JSON extraction.
#   - The fixture login throttle is 5 attempts/minute (SWASTHYA_RATE_LIMIT_AUTH
#     default) and this script performs 4 logins; consecutive re-runs within
#     the same minute may hit the throttle and fail closed — wait a minute or
#     use the refresh-token flow before re-running.
#
# ENVIRONMENT (all values are entered by the operator — never hard-coded)
#   STAGING_BASE_URL           (required)  API base URL, e.g.
#                                         https://swasthya-api.onrender.com.
#                                         MUST be https://, or http:// only to
#                                         a loopback host (127.0.0.1 /
#                                         localhost) for the local staging
#                                         mirror.
#   STAGING_FIXTURE_PASSWORD   (required)  the StagingFixtureSeeder password
#                                         (the same value the seeder was run
#                                         with; STAGING_FIXTURE_PASSWORD env
#                                         on the staging host, documented
#                                         default SmokePass-2026! for the
#                                         disposable mirror).
#   STAGING_EMAIL_ADMIN_A      (optional)  tenant A hospital admin login
#                                         (default smoke.hadmin@two.test).
#   STAGING_EMAIL_DOCTOR_A     (optional)  tenant A doctor login
#                                         (default smoke.doctor@two.test).
#   STAGING_EMAIL_NURSE_A      (optional)  tenant A nurse login
#                                         (default smoke.nurse@two.test).
#   STAGING_EMAIL_ADMIN_B      (optional)  tenant B hospital admin login
#                                         (default smoke.hadmin@three.test).
#   SMOKE_DRY_RUN              (optional)  1 = validate environment and
#                                         prerequisites only; perform NO
#                                         HTTP requests.
#
# EXIT CODES
#   0  all smoke steps passed
#   1  a smoke step failed (HTTP status mismatch, missing field, or curl
#      failure) — see the printed step label and non-PHI reason
#   2  environment/usage error (missing env var, invalid URL, no PHP/curl)
#
# WHAT THE SMOKE COVERS (all through the real API, fixture tenants only)
#   1. health  — /health/live and /health/ready over HTTPS (or loopback)
#   2. auth+context — login each actor; /auth/me; /organizations returns the
#      caller's own tenant code and never the other tenant's
#   3. OPD chain — register patient -> book appointment (availability-derived
#      slot) -> check-in -> queue -> encounter -> note -> diagnosis ->
#      prescription -> sign -> invoice -> payment -> audit trail
#   4. RPM — consent -> device enroll (nurse) -> activate -> ingest abnormal
#      reading (admin) -> alert raised -> acknowledge (doctor)
#   5. CDSS — knowledge check on the proposed prescription (200, never
#      blocks care; fail-open contract)
#   6. AI — register a feature (kill switch OFF by default, no approved
#      model) -> invoke -> envelope degrades loudly (200, available=false,
#      degraded=true) — the boundary never transmits
#   7. Isolation — tenant B patient is invisible (404) and unwritable (403)
#      from tenant A, and vice versa; no existence leak, no data printed
#   8. Audit — the audit-events surface is reachable and non-empty
#
# MANUAL BOUNDARIES (not automated here — documented, not claimed)
#   - Browser-level E2E + accessibility: frontend/playwright.staging.config.ts
#   - Live AI inference / national integrations: NOT PRESENT by design
#     (no approved model, empty egress allowlist — this smoke asserts the
#     fail-closed state)
#   - Object-storage document uploads/downloads (no upload path exists yet)
#   - The fixture doctor's Tuesday schedule has finite capacity; many
#     consecutive runs may exhaust slots — reseed or wait for the next
#     Tuesday before re-running.
#   - Smoke rows persist as clearly-labeled synthetic data (no delete
#     endpoints exist); they are safe to ignore and are regenerated with a
#     unique suffix on every run.
# ============================================================================
set -euo pipefail

SMOKE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# --- Environment ------------------------------------------------------------
BASE_URL="${STAGING_BASE_URL:-}"
FIXTURE_PASSWORD="${STAGING_FIXTURE_PASSWORD:-}"
EMAIL_ADMIN_A="${STAGING_EMAIL_ADMIN_A:-smoke.hadmin@two.test}"
EMAIL_DOCTOR_A="${STAGING_EMAIL_DOCTOR_A:-smoke.doctor@two.test}"
EMAIL_NURSE_A="${STAGING_EMAIL_NURSE_A:-smoke.nurse@two.test}"
EMAIL_ADMIN_B="${STAGING_EMAIL_ADMIN_B:-smoke.hadmin@three.test}"
DRY_RUN="${SMOKE_DRY_RUN:-0}"

# --- Prerequisites ----------------------------------------------------------
command -v curl >/dev/null 2>&1 || { echo "SMOKE ERROR: curl is required on PATH."; exit 2; }

PHP_BIN=""
if command -v php >/dev/null 2>&1; then
    PHP_BIN="$(command -v php)"
elif [[ -x "$SMOKE_DIR/../.toolchain/php/php.exe" ]]; then
    PHP_BIN="$SMOKE_DIR/../.toolchain/php/php.exe"
else
    echo "SMOKE ERROR: PHP is required (on PATH or at .toolchain/php/php.exe) for JSON extraction."
    exit 2
fi


# --- URL safety (never smoke a non-HTTPS non-loopback host) -----------------
if [[ -z "$BASE_URL" ]]; then
    echo "SMOKE ERROR: STAGING_BASE_URL is required (e.g. https://swasthya-api.onrender.com)."
    exit 2
fi
BASE_URL="${BASE_URL%/}"
case "$BASE_URL" in
    https://*) ;;
    http://127.0.0.1*|http://localhost*) ;;
    *)
        echo "SMOKE ERROR: STAGING_BASE_URL must be https:// (or http://127.0.0.1|localhost for the local mirror). Refusing to smoke a plain-HTTP remote host."
        exit 2
        ;;
esac

if [[ -z "$FIXTURE_PASSWORD" ]]; then
    echo "SMOKE ERROR: STAGING_FIXTURE_PASSWORD is required (the StagingFixtureSeeder password)."
    exit 2
fi

# --- JSON extraction (PHP — a documented prerequisite, present in the API
# --- image and at .toolchain/php locally) -----------------------------------
# json_field <dot-path> : stdin JSON -> scalar at dot-path ("" on missing).
#                         Path syntax: key.key or key[0].key.
# json_pick <php-expr>  : stdin JSON -> result of a PHP expression over $d
#                         (the decoded root).
# json_array_len        : stdin JSON array -> element count (0 on non-array).
json_field() {
    local path="$1"
    # Accept both jq-style (.data.status) and bare (data.status) paths.
    path="${path#.}"
    "$PHP_BIN" -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        foreach (preg_split("/\\.(?!\\[)/", $argv[1]) as $seg) {
            if (preg_match("/^([^\\[]+)\\[(\\d+)\\]$/", $seg, $m)) {
                $d = (is_array($d) && isset($d[$m[1]]) && isset($d[$m[1]][(int) $m[2]])) ? $d[$m[1]][(int) $m[2]] : null;
            } else {
                $d = (is_array($d) && array_key_exists($seg, $d)) ? $d[$seg] : null;
            }
            if ($d === null) { break; }
        }
        if (is_string($d) || is_int($d) || is_float($d)) { echo $d; }
        elseif (is_bool($d)) { echo $d ? "true" : "false"; }
        elseif ($d === null) { echo "null"; }
        else { echo json_encode($d); }
    ' "$path" 2>/dev/null || true
}

json_pick() {
    local expr="$1"
    "$PHP_BIN" -r '
        $d = json_decode(stream_get_contents(STDIN), true);
        $r = '"$expr"';
        if (is_string($r) || is_int($r) || is_float($r)) { echo $r; }
        elseif (is_bool($r)) { echo $r ? "true" : "false"; }
        elseif ($r === null) { echo "null"; }
        else { echo json_encode($r); }
    ' 2>/dev/null || true
}

json_array_len() {
    "$PHP_BIN" -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? count($d) : 0;' 2>/dev/null || true
}

# --- Helpers ----------------------------------------------------------------
FAILED_STEP=""
fail() { # $1 = step label, $2 = non-PHI reason
    echo "SMOKE FAILED — ${1}: ${2}"
    echo "Manual steps and boundaries: STAGING.md §11 (smoke), RENDER_STAGING.md §4 (post-deploy verification)."
    exit 1
}

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
HTTP_BODY="$TMP_DIR/body.json"
HTTP_ERR="$TMP_DIR/curl.err"

# http <method> <path> [json-body] [token]  -> sets HTTP_STATUS; body in $HTTP_BODY
http() {
    local method="$1" path="$2" body="${3:-}" token="${4:-}"
    local args=(-sS -o "$HTTP_BODY" -w '%{http_code}' -X "$method" "${BASE_URL}${path}"
        -H 'Accept: application/json' -H 'User-Agent: swasthya-smoke/1.0')
    if [[ -n "$body" ]]; then
        args+=(-H 'Content-Type: application/json' --data "$body")
    fi
    if [[ -n "$token" ]]; then
        args+=(-H "Authorization: Bearer ${token}")
    fi
    if ! HTTP_STATUS="$(curl "${args[@]}" 2>"$HTTP_ERR")"; then
        local reason
        reason="$(head -c 300 "$HTTP_ERR" 2>/dev/null || true)"
        [[ -n "$reason" ]] || reason="unknown transport error"
        fail "${CURRENT_STEP:-http}" "curl failed for ${path}: ${reason}"
    fi
}

# step <label> — records the current step for failure diagnostics
step() {
    CURRENT_STEP="$1"
    echo "  smoke [${1}] ..."
}

# assert_status <expected> <label>
assert_status() {
    local expected="$1" label="$2"
    if [[ "$HTTP_STATUS" != "$expected" ]]; then
        local code=""
        code="$(json_field '.error.code' < "$HTTP_BODY")"
        fail "$label" "expected HTTP ${expected}, got ${HTTP_STATUS}${code:+" (error code: ${code})"}"
    fi
}

# assert_status_in <space-separated-acceptable> <label> — safe-denial set
assert_status_in() {
    local acceptable=" $1 " label="$2"
    if [[ "$acceptable" != *" ${HTTP_STATUS} "* ]]; then
        local code=""
        code="$(json_field '.error.code' < "$HTTP_BODY")"
        fail "$label" "expected one of [${acceptable// /|}], got ${HTTP_STATUS}${code:+" (error code: ${code})"}"
    fi
}

# login <email> <label> -> sets TOKEN
login() {
    local email="$1" label="$2"
    http POST /api/v1/auth/login "{\"email\":\"${email}\",\"password\":\"${FIXTURE_PASSWORD}\"}"
    assert_status 200 "$label login"
    TOKEN="$(json_field '.data.accessToken' < "$HTTP_BODY")"
    if [[ -z "$TOKEN" || "$TOKEN" == "null" ]]; then
        fail "$label login" "response carried no access token"
    fi
}

# --- Smoke steps ------------------------------------------------------------
run_smoke() {
    local ts
    ts="$(date +%Y%m%d%H%M%S)-$$"
    local suffix="smoke-${ts}"

    # 1. Health --------------------------------------------------------------
    step "health/live"
    http GET /api/v1/health/live
    assert_status 200 "health/live"
    local live_status
    live_status="$(json_field '.data.status' < "$HTTP_BODY")"
    if [[ "$live_status" != "ok" ]]; then
        fail "health/live" "expected data.status=ok, got '${live_status}'"
    fi

    step "health/ready"
    http GET /api/v1/health/ready
    assert_status 200 "health/ready"

    # 2. Auth + tenant context ----------------------------------------------
    step "login admin A (hospital_admin, tenant A)"
    login "$EMAIL_ADMIN_A" "admin A"
    local token_admin_a="$TOKEN"

    step "login doctor A (doctor, tenant A)"
    login "$EMAIL_DOCTOR_A" "doctor A"
    local token_doctor_a="$TOKEN"

    step "login nurse A (nurse, tenant A)"
    login "$EMAIL_NURSE_A" "nurse A"
    local token_nurse_a="$TOKEN"

    step "login admin B (hospital_admin, tenant B)"
    login "$EMAIL_ADMIN_B" "admin B"
    local token_admin_b="$TOKEN"

    # Tenant context: /organizations must show ONLY the caller's tenant.
    step "tenant context A (organizations list)"
    http GET /api/v1/organizations "" "$token_admin_a"
    assert_status 200 "tenant context A"
    local orgs_a codes_a
    orgs_a="$(json_field '.data' < "$HTTP_BODY")"
    codes_a="$(printf '%s' "$orgs_a" | json_pick '$d ? implode(",", array_column($d, "code")) : ""')"
    if [[ "$codes_a" != *"smoke-group"* ]]; then
        fail "tenant context A" "organizations list did not include smoke-group"
    fi
    if [[ "$codes_a" == *"apex-care"* ]]; then
        fail "tenant context A" "organizations list leaked the other tenant (apex-care)"
    fi

    step "tenant context B (organizations list)"
    http GET /api/v1/organizations "" "$token_admin_b"
    assert_status 200 "tenant context B"
    orgs_a="$(json_field '.data' < "$HTTP_BODY")"
    codes_a="$(printf '%s' "$orgs_a" | json_pick '$d ? implode(",", array_column($d, "code")) : ""')"
    if [[ "$codes_a" != *"apex-care"* ]]; then
        fail "tenant context B" "organizations list did not include apex-care"
    fi
    if [[ "$codes_a" == *"smoke-group"* ]]; then
        fail "tenant context B" "organizations list leaked the other tenant (smoke-group)"
    fi

    # 3. OPD chain (tenant A) ------------------------------------------------
    step "OPD: org / staff / service / medication ids"
    local org_id staff_id service_id medication_id
    http GET /api/v1/organizations "" "$token_admin_a"
    assert_status 200 "OPD org id"
    org_id="$(json_field '.data[0].id' < "$HTTP_BODY")"
    http GET "/api/v1/organizations/${org_id}/staff" "" "$token_admin_a"
    assert_status 200 "OPD staff list"
    staff_id="$(json_field '.data[0].id' < "$HTTP_BODY")"
    http GET "/api/v1/organizations/${org_id}/services" "" "$token_admin_a"
    assert_status 200 "OPD services"
    service_id="$(json_field '.data[0].id' < "$HTTP_BODY")"
    http GET "/api/v1/organizations/${org_id}/medications" "" "$token_admin_a"
    assert_status 200 "OPD medications"
    medication_id="$(json_field '.data[0].id' < "$HTTP_BODY")"
    if [[ -z "$org_id" || -z "$staff_id" || -z "$service_id" || -z "$medication_id" ]]; then
        fail "OPD ids" "fixture ids could not be resolved (org/staff/service/medication)"
    fi

    step "OPD: derive an open appointment slot (fixture doctor, next Tuesday)"
    local slot_date slot_starts slot_ends
    slot_date="$("$PHP_BIN" -r 'echo date("Y-m-d", strtotime("next tuesday"));')"
    http GET "/api/v1/staff/${staff_id}/availability?date=${slot_date}" "" "$token_admin_a"
    assert_status 200 "OPD availability"
    slot_starts="$(json_field '.data[0].startsAt' < "$HTTP_BODY")"
    slot_ends="$(json_field '.data[0].endsAt' < "$HTTP_BODY")"
    if [[ -z "$slot_starts" || -z "$slot_ends" ]]; then
        fail "OPD availability" "no open slot on ${slot_date} (fixture schedule exhausted?)"
    fi

    step "OPD: register patient"
    local patient_a
    http POST "/api/v1/organizations/${org_id}/patients" \
        "{\"facilityId\":\"\",\"fullName\":\"Smoke Patient ${suffix}\",\"dateOfBirth\":\"1990-01-01\",\"sex\":\"female\",\"phone\":\"+977-9800-000000\"}" \
        "$token_admin_a"
    assert_status 201 "OPD patient registration"
    patient_a="$(json_field '.data.id' < "$HTTP_BODY")"

    step "OPD: book appointment"
    local appointment_id
    http POST /api/v1/appointments \
        "{\"patientId\":\"${patient_a}\",\"providerStaffId\":\"${staff_id}\",\"serviceId\":\"${service_id}\",\"startsAt\":\"${slot_starts}\",\"endsAt\":\"${slot_ends}\"}" \
        "$token_admin_a"
    assert_status 201 "OPD appointment booking"
    appointment_id="$(json_field '.data.id' < "$HTTP_BODY")"

    step "OPD: check-in"
    http POST "/api/v1/appointments/${appointment_id}/check-in" "" "$token_admin_a"
    assert_status 200 "OPD check-in"
    local checked_status
    checked_status="$(json_field '.data.status' < "$HTTP_BODY")"
    if [[ "$checked_status" != "checked_in" ]]; then
        fail "OPD check-in" "expected status checked_in, got '${checked_status}'"
    fi

    step "OPD: queue shows the token"
    http GET "/api/v1/appointments/queue?date=${slot_date}" "" "$token_admin_a"
    assert_status 200 "OPD queue"
    local queue_count
    queue_count="$(json_field '.data' < "$HTTP_BODY" | json_array_len)"
    if [[ "$queue_count" == "0" || -z "$queue_count" ]]; then
        fail "OPD queue" "queue was empty for the checked-in appointment"
    fi

    step "OPD: start encounter"
    local encounter_id
    http POST "/api/v1/appointments/${appointment_id}/start-encounter" "" "$token_doctor_a"
    assert_status 201 "OPD start encounter"
    encounter_id="$(json_field '.data.id' < "$HTTP_BODY")"

    step "OPD: clinical note"
    local note_id
    http POST "/api/v1/encounters/${encounter_id}/notes" \
        '{"content":{"complaint":"Smoke complaint","history":"Smoke history","examination":"Smoke examination"}}' \
        "$token_doctor_a"
    assert_status 201 "OPD note"
    note_id="$(json_field '.data.id' < "$HTTP_BODY")"

    step "OPD: diagnosis"
    http POST "/api/v1/encounters/${encounter_id}/diagnoses" \
        '{"code":"Z00.0","codingSystem":"icd10","description":"Smoke diagnosis","diagnosisType":"final","isPrimary":true}' \
        "$token_doctor_a"
    assert_status 201 "OPD diagnosis"

    step "OPD: prescription"
    http POST "/api/v1/encounters/${encounter_id}/prescriptions" \
        "{\"notes\":\"Smoke prescription\",\"lines\":[{\"medicationId\":\"${medication_id}\",\"dose\":\"1 tablet\",\"route\":\"oral\",\"frequency\":\"three times daily\",\"duration\":\"5 days\",\"quantityMinor\":15}]}" \
        "$token_doctor_a"
    assert_status 201 "OPD prescription"

    step "OPD: sign note and encounter"
    http POST "/api/v1/encounters/${encounter_id}/notes/${note_id}/sign" "" "$token_doctor_a"
    assert_status 200 "OPD sign note"
    http POST "/api/v1/encounters/${encounter_id}/sign" "" "$token_doctor_a"
    assert_status 200 "OPD sign encounter"

    step "OPD: invoice"
    local invoice_id total_minor
    http POST "/api/v1/encounters/${encounter_id}/invoice" '{"chargeIds":[]}' "$token_admin_a"
    assert_status 201 "OPD invoice"
    invoice_id="$(json_field '.data.id' < "$HTTP_BODY")"
    total_minor="$(json_field '.data.totalMinor' < "$HTTP_BODY")"

    step "OPD: payment"
    http POST "/api/v1/invoices/${invoice_id}/pay" \
        "{\"method\":\"cash\",\"amountMinor\":${total_minor},\"idempotencyKey\":\"${suffix}-pay\"}" \
        "$token_admin_a"
    assert_status 201 "OPD payment"
    local paid_status
    paid_status="$(json_field '.data.invoice.status' < "$HTTP_BODY")"
    if [[ "$paid_status" != "paid" ]]; then
        fail "OPD payment" "expected invoice.status paid, got '${paid_status}'"
    fi

    # 4. RPM (tenant A) -------------------------------------------------------
    step "RPM: capture device-monitoring consent"
    http POST "/api/v1/patients/${patient_a}/consents" '{"consentType":"device_monitoring"}' "$token_admin_a"
    assert_status 201 "RPM consent"

    step "RPM: enroll device (nurse)"
    local device_id
    http POST /api/v1/rpm/devices \
        "{\"patientId\":\"${patient_a}\",\"deviceIdentifier\":\"SMOKE-DEV-${suffix}\",\"readingType\":\"pulse\"}" \
        "$token_nurse_a"
    assert_status 201 "RPM device enroll"
    device_id="$(json_field '.data.id' < "$HTTP_BODY")"

    step "RPM: activate device (nurse)"
    http PATCH "/api/v1/rpm/devices/${device_id}" '{"status":"active"}' "$token_nurse_a"
    assert_status 200 "RPM device activate"

    step "RPM: ingest abnormal reading (admin, machine path)"
    http POST /api/v1/rpm/readings \
        "{\"readings\":[{\"deviceIdentifier\":\"SMOKE-DEV-${suffix}\",\"ingestionId\":\"${suffix}-ing-1\",\"readingType\":\"pulse\",\"value\":{\"value\":140}}]}" \
        "$token_admin_a"
    assert_status 200 "RPM ingest"
    local validation_status
    validation_status="$(json_field '.data[0].validationStatus' < "$HTTP_BODY")"
    if [[ "$validation_status" != "flagged" ]]; then
        fail "RPM ingest" "expected validationStatus flagged, got '${validation_status}'"
    fi

    step "RPM: alert raised and acknowledged (doctor)"
    local alert_id
    http GET /api/v1/rpm/alerts "" "$token_doctor_a"
    assert_status 200 "RPM alerts"
    alert_id="$(printf '%s' "$(json_field '.data' < "$HTTP_BODY")" | json_pick '
        $r = null;
        foreach ($d as $a) { if (($a["status"] ?? "") === "open") { $r = $a["id"]; break; } }
        $r')"
    if [[ -z "$alert_id" || "$alert_id" == "null" ]]; then
        fail "RPM alert" "no open alert after the flagged reading"
    fi
    http POST "/api/v1/rpm/alerts/${alert_id}/acknowledge" "{\"note\":\"Smoke acknowledgment ${suffix}\"}" "$token_doctor_a"
    assert_status 200 "RPM alert acknowledge"

    # 5. CDSS (tenant A) ------------------------------------------------------
    step "CDSS: knowledge check on the proposed prescription"
    http POST /api/v1/cdss/checks/prescription \
        "{\"patientId\":\"${patient_a}\",\"lines\":[{\"medicationId\":\"${medication_id}\",\"dose\":\"1 tablet\",\"route\":\"oral\",\"frequency\":\"three times daily\"}]}" \
        "$token_admin_a"
    assert_status 200 "CDSS check"
    local cdss_degraded
    cdss_degraded="$(json_field '.meta.degraded' < "$HTTP_BODY")"
    echo "  smoke [CDSS] meta.degraded=${cdss_degraded} (fail-open: care is never blocked)"

    # 6. AI governance (tenant A) ---------------------------------------------
    step "AI: register feature (kill switch OFF by default, no approved model)"
    local feature_id
    http POST /api/v1/ai/features \
        "{\"function\":\"smoke-assist-${suffix}\",\"name\":\"Smoke Assist ${suffix}\",\"tier\":2,\"modelId\":\"smoke-model-0\",\"modelVersion\":\"0.0.0\",\"purpose\":\"Staging smoke verification only; never transmits (no approved model).\"}" \
        "$token_admin_a"
    assert_status 201 "AI feature register"
    feature_id="$(json_field '.data.id' < "$HTTP_BODY")"

    step "AI: invoke degrades loudly (no approved model / kill switch off)"
    http POST "/api/v1/ai/features/${feature_id}/invoke" '{"context":{"note":"smoke"}}' "$token_doctor_a"
    assert_status 200 "AI invoke"
    local ai_degraded ai_available
    ai_degraded="$(json_field '.meta.degraded' < "$HTTP_BODY")"
    ai_available="$(json_field '.data.available' < "$HTTP_BODY")"
    if [[ "$ai_degraded" != "true" || "$ai_available" != "false" ]]; then
        fail "AI invoke" "expected degraded envelope (degraded=true, available=false), got degraded=${ai_degraded} available=${ai_available}"
    fi

    # 7. Cross-tenant isolation (A cannot touch B, B cannot touch A) ----------
    step "isolation: tenant B registers a patient"
    local patient_b org_b
    http GET /api/v1/organizations "" "$token_admin_b"
    assert_status 200 "isolation org B"
    org_b="$(json_field '.data[0].id' < "$HTTP_BODY")"
    http POST "/api/v1/organizations/${org_b}/patients" \
        "{\"facilityId\":\"\",\"fullName\":\"Smoke B Patient ${suffix}\",\"dateOfBirth\":\"1991-02-02\",\"sex\":\"male\",\"phone\":\"+977-9800-000001\"}" \
        "$token_admin_b"
    assert_status 201 "isolation patient B"
    patient_b="$(json_field '.data.id' < "$HTTP_BODY")"

    step "isolation: tenant A CANNOT read tenant B's patient (404, no existence leak)"
    http GET "/api/v1/patients/${patient_b}" "" "$token_admin_a"
    assert_status 404 "isolation read B from A"

    step "isolation: tenant A CANNOT update tenant B's patient (safe denial)"
    http PATCH "/api/v1/patients/${patient_b}" '{"fullName":"Smoke Forged"}' "$token_admin_a"
    # The repo's cross-tenant attack contract accepts 403/404/422 as safe
    # denials (CrossTenantApiAttackTest — 422 = validation-before-scope).
    assert_status_in "403 404 422" "isolation write B from A"

    step "isolation: tenant B's patient is untouched after the attack"
    http GET "/api/v1/patients/${patient_b}" "" "$token_admin_b"
    assert_status 200 "isolation B row intact"
    local forged_name
    forged_name="$(json_field '.data.fullName' < "$HTTP_BODY")"
    if [[ "$forged_name" == "Smoke Forged" ]]; then
        fail "isolation B row intact" "tenant A's write reached tenant B's row"
    fi

    step "isolation: tenant B CANNOT read tenant A's patient (404, no existence leak)"
    http GET "/api/v1/patients/${patient_a}" "" "$token_admin_b"
    assert_status 404 "isolation read A from B"

    # 8. Audit ----------------------------------------------------------------
    step "audit: trail reachable and non-empty (tenant A)"
    http GET /api/v1/audit-events "" "$token_admin_a"
    assert_status 200 "audit events"
    local audit_count
    audit_count="$(json_field '.data' < "$HTTP_BODY" | json_array_len)"
    if [[ "$audit_count" == "0" || -z "$audit_count" ]]; then
        fail "audit events" "audit trail was empty after the OPD/RPM/CDSS/AI chain"
    fi
}

# --- Dry-run / real run -----------------------------------------------------
if [[ "$DRY_RUN" == "1" ]]; then
    echo "SMOKE DRY RUN — environment valid:"
    echo "  STAGING_BASE_URL=${BASE_URL}"
    echo "  STAGING_FIXTURE_PASSWORD=<set> (${#FIXTURE_PASSWORD} chars)"
    echo "  actors: adminA=${EMAIL_ADMIN_A} doctorA=${EMAIL_DOCTOR_A} nurseA=${EMAIL_NURSE_A} adminB=${EMAIL_ADMIN_B}"
    echo "  json: php ($PHP_BIN)"
    echo "  curl: $(command -v curl)"
    echo "No HTTP requests were made."
    exit 0
fi

echo "Swasthya staging smoke — ${BASE_URL}"
run_smoke
echo
echo "SMOKE OK — all staging smoke steps passed (${BASE_URL})"
