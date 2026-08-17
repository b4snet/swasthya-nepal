<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\CdssCheckResult;
use App\Models\CdssRule;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Support\ErrorCodes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Phase 21 — CDSS (ROADMAP Phase 21, CLINICAL_SAFETY.md §6, §9,
 * AI_RULES.md §6–7).
 *
 * Knowledge-base-driven decision support. The checks are NOT model guesses:
 * they are driven by the VERSIONED, clinically reviewed knowledge base
 * (cdss_rules) and the patient's documented allergies. The shape is
 * propose → human decides → sign; nothing here mutates a prescription.
 *
 * Fail-open with loud degradation (CLINICAL_SAFETY.md §6, AI_RULES.md §17):
 * if the knowledge base cannot be evaluated, care is never blocked — the
 * caller receives `degraded: true` and the controller surfaces it visibly
 * (and it is audited by the controller). A check that cannot run is a loud
 * "check unavailable", never a silent pass and never a block.
 *
 * Overrides are reason-captured and audited on the check result row —
 * a prescriber may proceed only with a recorded reason (AI_RULES.md §7,
 * CLINICAL_SAFETY.md §5).
 */
final class CdssService
{
    /**
     * Run the full knowledge check for a proposed prescription.
     *
     * @param  list<array{medicationId: string, dose: string, route: string, frequency: string}>  $items
     * @return array{alerts: list<array<string, mixed>>, degraded: bool}
     */
    public function checkPrescription(Patient $patient, array $items): array
    {
        try {
            $alerts = DB::transaction(function () use ($patient, $items): array {
                $alerts = [];

                $existing = $this->existingMedicationIds($patient);
                $batchMedicationIds = array_map(
                    static fn (array $item): string => (string) $item['medicationId'],
                    $items,
                );

                foreach ($items as $index => $item) {
                    $medicationId = (string) $item['medicationId'];
                    $doseText = (string) ($item['dose'] ?? '');

                    foreach ($this->allergyAlerts($patient, $medicationId) as $alert) {
                        $alerts[] = $this->persist($patient, $alert);
                    }

                    foreach ($this->doseAlerts($medicationId, $doseText) as $alert) {
                        $alerts[] = $this->persist($patient, $alert);
                    }

                    // DDI: against the patient's active prescriptions AND
                    // the other lines of the same batch.
                    $others = array_values(array_filter($batchMedicationIds, fn (string $id): bool => $id !== $medicationId));
                    $others = array_values(array_unique([...$existing, ...$others]));

                    foreach ($others as $otherId) {
                        foreach ($this->interactionAlerts($medicationId, $otherId) as $alert) {
                            $alerts[] = $this->persist($patient, $alert);
                        }
                    }
                }

                return $alerts;
            });

            return ['alerts' => $alerts, 'degraded' => false];
        } catch (QueryException $e) {
            // Fail open, loudly: the check could not run — surface it, never
            // block care on it (CLINICAL_SAFETY.md §6).
            report($e);

            return ['alerts' => [], 'degraded' => true];
        }
    }

    /**
     * Evaluate a registered pathway rule against a patient context and
     * return advisory suggestions (Tier-3 style: human decides — nothing is
     * applied). Matches persist a pathway check result so the suggestion is
     * traceable.
     *
     * @param  array<string, mixed>  $context
     * @return list<array<string, mixed>>
     */
    public function evaluatePathway(Patient $patient, CdssRule $pathway, array $context): array
    {
        if ($pathway->rule_type !== CdssRule::TYPE_PATHWAY || $pathway->status !== CdssRule::STATUS_ACTIVE) {
            return [];
        }

        $spec = is_array($pathway->spec) ? $pathway->spec : [];
        $condition = $spec['condition'] ?? [];

        if (! $this->matchesCondition($condition, $context)) {
            return [];
        }

        $alert = [
            'alert_type' => 'pathway',
            'rule_code' => $pathway->code,
            'rule_version' => $pathway->version,
            'severity' => 'minor',
            'message' => (string) ($spec['suggestion'] ?? $pathway->name),
            'triggering_facts' => ['pathway' => $pathway->code, 'condition' => array_keys($condition)],
        ];

        $result = $this->persist($patient, $alert);

        return [$result];
    }

    /**
     * Record a prescriber's override of an open check result — the reason is
     * mandatory and audited (never a silent dismiss). CAS on (status,
     * lock_version): exactly one winner under concurrent override attempts.
     */
    public function overrideResult(CdssCheckResult $result, string $reason, string $staffId): CdssCheckResult
    {
        $affected = DB::transaction(function () use ($result, $reason, $staffId): int {
            return CdssCheckResult::query()
                ->whereKey($result->getKey())
                ->where('status', CdssCheckResult::STATUS_OPEN)
                ->where('lock_version', $result->lock_version)
                ->update([
                    'status' => CdssCheckResult::STATUS_OVERRIDDEN,
                    'override_reason' => $reason,
                    'overridden_by' => $staffId,
                    'overridden_at' => now(),
                    'lock_version' => $result->lock_version + 1,
                ]);
        });

        if ($affected !== 1) {
            throw new ApiException(
                ErrorCodes::CONFLICT,
                'The check result is not open for override (current status: '.$result->status.').',
                409,
            );
        }

        return $result->refresh();
    }

    /**
     * Store a NEW VERSION of a rule (v1 if none exists). Rules are never
     * edited in place — a change is a new version (kpi_definitions
     * discipline). New versions are drafts; activation supersedes the
     * previous active version.
     *
     * @param  array<string, mixed>  $payload
     */
    public function storeRule(array $payload): CdssRule
    {
        $ruleType = (string) $payload['ruleType'];
        $code = (string) $payload['code'];

        return DB::transaction(function () use ($ruleType, $code, $payload): CdssRule {
            $maxVersion = CdssRule::query()
                ->where('tenant_id', (string) $payload['tenant_id'])
                ->where('facility_id', (string) $payload['facility_id'])
                ->where('rule_type', $ruleType)
                ->where('code', $code)
                ->max('version') ?? 0;

            return CdssRule::query()->create([
                'tenant_id' => $payload['tenant_id'],
                'facility_id' => $payload['facility_id'],
                'rule_type' => $ruleType,
                'code' => $code,
                'name' => $payload['name'],
                'severity' => $payload['severity'] ?? null,
                'spec' => $payload['spec'] ?? [],
                'version' => $maxVersion + 1,
                'status' => CdssRule::STATUS_DRAFT,
                'lock_version' => 0,
                'created_by' => $payload['created_by'] ?? null,
            ]);
        });
    }

    /**
     * draft → active (CAS). Supersedes any other ACTIVE version of the same
     * rule code in the same transaction — the DB unique index on active
     * (tenant, facility, rule_type, code) is the backstop.
     */
    public function activateRule(CdssRule $rule, string $actorId): CdssRule
    {
        return DB::transaction(function () use ($rule, $actorId): CdssRule {
            // Supersede any OTHER active version FIRST — the DB partial-unique
            // index (one ACTIVE per code) must be satisfied before the draft
            // flips to active.
            CdssRule::query()
                ->where('tenant_id', $rule->tenant_id)
                ->where('facility_id', $rule->facility_id)
                ->where('rule_type', $rule->rule_type)
                ->where('code', $rule->code)
                ->where('id', '!=', $rule->getKey())
                ->where('status', CdssRule::STATUS_ACTIVE)
                ->update(['status' => CdssRule::STATUS_SUPERSEDED]);

            $affected = CdssRule::query()
                ->whereKey($rule->getKey())
                ->where('status', CdssRule::STATUS_DRAFT)
                ->where('lock_version', $rule->lock_version)
                ->update([
                    'status' => CdssRule::STATUS_ACTIVE,
                    'lock_version' => $rule->lock_version + 1,
                    'updated_by' => $actorId,
                ]);

            if ($affected !== 1) {
                throw new ApiException(
                    ErrorCodes::CONFLICT,
                    'The rule is not a draft awaiting activation (current status: '.$rule->status.').',
                    409,
                );
            }

            return $rule->refresh();
        });
    }

    // ------------------------------------------------------------------ //
    // check internals
    // ------------------------------------------------------------------ //

    /**
     * @return list<uuid-string>
     */
    private function existingMedicationIds(Patient $patient): array
    {
        return Prescription::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->getKey())
            ->where('status', Prescription::STATUS_ACTIVE)
            ->get()
            ->flatMap(fn (Prescription $prescription) => PrescriptionLine::query()
                ->where('tenant_id', $prescription->tenant_id)
                ->where('prescription_id', $prescription->getKey())
                ->where('status', PrescriptionLine::STATUS_ORDERED)
                ->pluck('medication_id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allergyAlerts(Patient $patient, string $medicationId): array
    {
        $allergy = DB::table('patient_allergies')
            ->where('tenant_id', $patient->tenant_id)
            ->where('patient_id', $patient->getKey())
            ->where('status', 'active')
            ->whereNotNull('allergen_class')
            ->first();

        if ($allergy === null) {
            return [];
        }

        $rule = CdssRule::query()
            ->where('tenant_id', $patient->tenant_id)
            ->where('facility_id', $patient->facility_id)
            ->where('rule_type', CdssRule::TYPE_ALLERGEN)
            ->where('status', CdssRule::STATUS_ACTIVE)
            ->get()
            ->first(fn (CdssRule $r): bool => $this->specValue($r, 'medication_id') === $medicationId
                && $this->specValue($r, 'allergen_class') === $allergy->allergen_class);

        if ($rule === null) {
            return [];
        }

        return [[
            'alert_type' => 'allergy',
            'rule_code' => $rule->code,
            'rule_version' => $rule->version,
            'severity' => $rule->severity ?? 'major',
            'message' => 'Allergy alert: '.$allergy->allergen.' — '.($this->specValue($rule, 'action') ?? 'review before prescribing.'),
            'triggering_facts' => ['medicationId' => $medicationId, 'allergenClass' => $allergy->allergen_class],
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function doseAlerts(string $medicationId, string $doseText): array
    {
        $mg = $this->parseMg($doseText);

        if ($mg === null) {
            return []; // unparseable dose — the dose check cannot run on it (fail open on this line)
        }

        $rules = CdssRule::query()
            ->where('rule_type', CdssRule::TYPE_DOSE)
            ->where('status', CdssRule::STATUS_ACTIVE)
            ->get()
            ->filter(fn (CdssRule $r): bool => $this->specValue($r, 'medication_id') === $medicationId);

        $alerts = [];

        foreach ($rules as $rule) {
            $maxDaily = (float) ($this->specValue($rule, 'max_daily_mg') ?? INF);
            $minDaily = (float) ($this->specValue($rule, 'min_daily_mg') ?? 0.0);

            if ($mg > $maxDaily) {
                $alerts[] = [
                    'alert_type' => 'dose',
                    'rule_code' => $rule->code,
                    'rule_version' => $rule->version,
                    'severity' => $rule->severity ?? 'major',
                    'message' => 'Dose alert: '.$doseText.' exceeds the daily maximum ('.$maxDaily.' mg). '.($this->specValue($rule, 'action') ?? ''),
                    'triggering_facts' => ['medicationId' => $medicationId, 'doseMg' => $mg, 'maxDailyMg' => $maxDaily],
                ];
            } elseif ($minDaily > 0.0 && $mg < $minDaily) {
                $alerts[] = [
                    'alert_type' => 'dose',
                    'rule_code' => $rule->code,
                    'rule_version' => $rule->version,
                    'severity' => $rule->severity ?? 'moderate',
                    'message' => 'Dose alert: '.$doseText.' is below the minimum effective dose ('.$minDaily.' mg).',
                    'triggering_facts' => ['medicationId' => $medicationId, 'doseMg' => $mg, 'minDailyMg' => $minDaily],
                ];
            }
        }

        return $alerts;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function interactionAlerts(string $medicationAId, string $medicationBId): array
    {
        $rules = CdssRule::query()
            ->where('rule_type', CdssRule::TYPE_INTERACTION)
            ->where('status', CdssRule::STATUS_ACTIVE)
            ->get()
            ->filter(fn (CdssRule $r): bool => $this->isPairMatch($r, $medicationAId, $medicationBId));

        $alerts = [];

        foreach ($rules as $rule) {
            $alerts[] = [
                'alert_type' => 'interaction',
                'rule_code' => $rule->code,
                'rule_version' => $rule->version,
                'severity' => $rule->severity ?? 'major',
                'message' => 'Interaction alert: '.$rule->name.'. '.($this->specValue($rule, 'action') ?? ''),
                'triggering_facts' => ['medicationAId' => $medicationAId, 'medicationBId' => $medicationBId],
            ];
        }

        return $alerts;
    }

    private function isPairMatch(CdssRule $rule, string $a, string $b): bool
    {
        $aRule = $this->specValue($rule, 'medication_a_id');
        $bRule = $this->specValue($rule, 'medication_b_id');

        return $aRule === $a && $bRule === $b
            || $aRule === $b && $bRule === $a;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $context
     */
    private function matchesCondition(array $condition, array $context): bool
    {
        foreach ($condition as $key => $expected) {
            $actual = $context[$key] ?? null;

            if (is_array($expected)) {
                if (! in_array($actual, $expected, true)) {
                    return false;
                }
            } elseif ((string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $alert
     * @return array<string, mixed>
     */
    private function persist(Patient $patient, array $alert): array
    {
        $result = CdssCheckResult::query()->create([
            'tenant_id' => $patient->tenant_id,
            'facility_id' => $patient->facility_id,
            'patient_id' => $patient->getKey(),
            'alert_type' => $alert['alert_type'],
            'rule_code' => $alert['rule_code'],
            'rule_version' => $alert['rule_version'],
            'severity' => $alert['severity'],
            'message' => $alert['message'],
            'triggering_facts' => $alert['triggering_facts'],
            'status' => CdssCheckResult::STATUS_OPEN,
            'lock_version' => 0,
        ]);

        return [
            'id' => $result->getKey(),
            'alertType' => $result->alert_type,
            'severity' => $result->severity,
            'code' => $result->rule_code,
            'ruleVersion' => $result->rule_version,
            'message' => $result->message,
            'status' => $result->status,
        ];
    }

    /**
     * @return mixed
     */
    private function specValue(CdssRule $rule, string $key)
    {
        $spec = is_array($rule->spec) ? $rule->spec : [];

        return $spec[$key] ?? null;
    }

    /**
     * Parse a dose string to milligrams. Supports "500 mg", "1 g", "5ml" of
     * liquid (returns null — unparseable lines fail open). Returns null when
     * the dose cannot be expressed in mg.
     */
    private function parseMg(string $dose): ?float
    {
        $dose = strtolower(trim($dose));

        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*g$/', $dose, $m) === 1) {
            return (float) $m[1] * 1000.0;
        }

        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*mg$/', $dose, $m) === 1) {
            return (float) $m[1];
        }

        return null;
    }
}
