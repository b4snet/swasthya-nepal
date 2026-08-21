<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generate synthetic large-scale data for performance benchmarking.
 *
 * Uses raw DB::table inserts (no Eloquent) for maximum throughput.
 * Supports incremental seeding (skip if already exists).
 */
final class PerformanceSeed extends Command
{
    protected $signature = 'perf:seed
        {--patients=100000 : Number of patients per facility}
        {--facilities=3 : Number of facilities per tenant}
        {--tenants=2 : Number of tenants}
        {--clean : Truncate performance tables before seeding}';

    protected $description = 'Generate synthetic data for national-scale performance benchmarking';

    private string $tenantId;

    private string $facilityId;

    private int $inserted = 0;

    private array $staffIds = [];

    private array $patientIds = [];

    private array $medicationIds = [];

    private array $encounterIds = [];

    public function handle(): int
    {
        if ($this->option('clean')) {
            $this->warn('Truncating performance data...');
            $this->truncateTables();
        }

        $patientCount = (int) $this->option('patients');
        $facilityCount = (int) $this->option('facilities');
        $tenantCount = (int) $this->option('tenants');

        $this->info("Seeding {$tenantCount} tenants × {$facilityCount} facilities × {$patientCount} patients each...");

        $start = microtime(true);

        $tenants = $this->seedTenants($tenantCount, $facilityCount);

        foreach ($tenants as ['tenantId' => $tenantId, 'facilityIds' => $facilityIds]) {
            $this->tenantId = $tenantId;

            foreach ($facilityIds as $fid) {
                $this->facilityId = $fid;

                // Skip if this facility already has patients
                $existingPatients = DB::table('patients')->where('facility_id', $fid)->count();
                if ($existingPatients >= $patientCount) {
                    $this->info("  Skipping tenant {$tenantId} facility {$fid} — {$existingPatients} patients already seeded.");

                    continue;
                }

                $remaining = max(0, $patientCount - $existingPatients);
                $this->info("  Seeding tenant {$tenantId} facility {$fid} ({$existingPatients} exist, seeding {$remaining} more)...");

                $deptIds = $this->seedDepartments($tenantId, $fid);
                $this->staffIds = $this->seedStaff($tenantId, $fid, $deptIds, 200);
                $this->medicationIds = $this->seedMedications($tenantId, $fid, 500);
                $this->patientIds = [];

                $this->seedPatients($tenantId, $fid, $remaining);

                $this->seedEncounters($tenantId, $fid, (int) (count($this->patientIds) * 4));
                $this->seedAppointments($tenantId, $fid, (int) ($remaining * 0.8));
                $this->seedLabOrders($tenantId, $fid, (int) (count($this->encounterIds) * 0.4));
                $this->seedPrescriptions($tenantId, $fid, (int) (count($this->encounterIds) * 0.3));
                $this->seedBilling($tenantId, $fid, count($this->encounterIds));
                $this->seedAuditEvents($tenantId, $fid, count($this->encounterIds) * 5);
                $this->seedInventory($tenantId, $fid, 2000);
            }
        }

        $elapsed = microtime(true) - $start;
        $this->info("Seeding complete in {$elapsed}s. Total inserts: {$this->inserted}");
        $this->printSummary();

        return self::SUCCESS;
    }

    private function truncateTables(): void
    {
        $tables = [
            'audit_events', 'stock_movements', 'stock_batches', 'inventory_items',
            'billing_adjustments', 'receipts', 'payments', 'invoices', 'charges',
            'prescription_lines', 'prescriptions', 'lab_order_items', 'lab_orders',
            'appointments', 'encounters', 'medications', 'departments', 'staff',
            'patients', 'facilities', 'organizations',
        ];

        foreach ($tables as $table) {
            try {
                DB::table($table)->truncate();
            } catch (\Throwable) {
                // Table may not exist
            }
        }
    }

    private function seedTenants(int $count, int $facilitiesPerTenant): array
    {
        $tenants = [];
        $now = now()->toDateTimeString();

        for ($i = 0; $i < $count; $i++) {
            $code = 'perf-group-'.$i;

            $existing = DB::table('organizations')->where('code', $code)->first();
            if ($existing) {
                $tenantId = $existing->id;
                $this->info("  Tenant {$code} already exists ({$tenantId}).");

                $existingFacs = DB::table('facilities')
                    ->where('tenant_id', $tenantId)
                    ->pluck('id')
                    ->toArray();

                $facilityIds = array_slice($existingFacs, 0, $facilitiesPerTenant);
                $needed = $facilitiesPerTenant - count($facilityIds);

                for ($f = 0; $f < $needed; $f++) {
                    $fid = Str::uuid()->toString();
                    DB::table('facilities')->insert([
                        'id' => $fid,
                        'tenant_id' => $tenantId,
                        'name' => 'Perf Facility '.(count($existingFacs) + $f),
                        'code' => "perf-fac-{$i}-".(count($existingFacs) + $f),
                        'status' => 'active',
                        'timezone' => 'Asia/Kathmandu',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $facilityIds[] = $fid;
                    $this->inserted++;
                }

                $tenants[] = ['tenantId' => $tenantId, 'facilityIds' => $facilityIds];

                continue;
            }

            $tenantId = Str::uuid()->toString();
            DB::table('organizations')->insert([
                'id' => $tenantId,
                'name' => "Perf Hospital Group {$i}",
                'code' => $code,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->inserted++;

            $facilityIds = [];
            for ($f = 0; $f < $facilitiesPerTenant; $f++) {
                $fid = Str::uuid()->toString();
                DB::table('facilities')->insert([
                    'id' => $fid,
                    'tenant_id' => $tenantId,
                    'name' => "Perf Facility {$f}",
                    'code' => "perf-fac-{$i}-{$f}",
                    'status' => 'active',
                    'timezone' => 'Asia/Kathmandu',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $facilityIds[] = $fid;
                $this->inserted++;
            }

            $tenants[] = ['tenantId' => $tenantId, 'facilityIds' => $facilityIds];
        }

        return $tenants;
    }

    private function seedDepartments(string $tenantId, string $facilityId): array
    {
        $existing = DB::table('departments')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->pluck('id', 'code')
            ->toArray();

        if (count($existing) >= 10) {
            return array_values($existing);
        }

        $names = [
            'OPD', 'Emergency', 'IPD', 'Surgery', 'Pharmacy',
            'Laboratory', 'Radiology', 'ICU', 'Cardiology', 'Pediatrics',
        ];
        $codes = ['opd', 'emg', 'ipd', 'surg', 'pharm', 'lab', 'rad', 'icu', 'card', 'peds'];
        $ids = array_values($existing);
        $now = now()->toDateTimeString();

        for ($i = count($existing); $i < 10; $i++) {
            $id = Str::uuid()->toString();
            DB::table('departments')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'name' => $names[$i],
                'code' => $codes[$i],
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ids[] = $id;
            $this->inserted++;
        }

        return $ids;
    }

    private function seedStaff(string $tenantId, string $facilityId, array $deptIds, int $count): array
    {
        $existing = DB::table('staff')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->pluck('id')
            ->toArray();

        if (count($existing) >= $count) {
            return array_slice($existing, 0, $count);
        }

        $ids = array_values($existing);
        $now = now()->toDateTimeString();
        $needed = $count - count($ids);
        $batch = [];

        for ($i = 0; $i < $needed; $i++) {
            $id = Str::uuid()->toString();
            $num = count($ids) + $i + 1;
            $batch[] = [
                'id' => $id,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'department_id' => $deptIds[array_rand($deptIds)],
                'full_name' => "Staff {$num} Perf",
                'employee_code' => 'STF-'.str_pad($num, 5, '0', STR_PAD_LEFT),
                'designation' => 'Doctor',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $ids[] = $id;
        }

        foreach (array_chunk($batch, 500) as $chunk) {
            DB::table('staff')->insert($chunk);
            $this->inserted += count($chunk);
        }

        return $ids;
    }

    private function seedMedications(string $tenantId, string $facilityId, int $count): array
    {
        $existing = DB::table('medications')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->pluck('id')
            ->toArray();

        if (count($existing) >= $count) {
            return array_slice($existing, 0, $count);
        }

        $ids = array_values($existing);
        $now = now()->toDateTimeString();
        $needed = $count - count($ids);
        $batch = [];
        $forms = ['tablet', 'capsule', 'syrup', 'injection'];

        for ($i = 0; $i < $needed; $i++) {
            $id = Str::uuid()->toString();
            $num = count($ids) + $i + 1;
            $batch[] = [
                'id' => $id,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'brand_name' => "Brand Med {$num}",
                'generic_name' => "Generic Med {$num}",
                'strength' => rand(1, 500).'mg',
                'code' => 'MED-'.str_pad($num, 5, '0', STR_PAD_LEFT),
                'form' => $forms[array_rand($forms)],
                'unit' => 'tablet',
                'is_controlled' => false,
                'status' => 'active',
                'price_minor' => rand(100, 50000),
                'currency' => 'NPR',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $ids[] = $id;
        }

        foreach (array_chunk($batch, 500) as $chunk) {
            DB::table('medications')->insert($chunk);
            $this->inserted += count($chunk);
        }

        return $ids;
    }

    private function seedPatients(string $tenantId, string $facilityId, int $count): void
    {
        $now = now()->toDateTimeString();
        $batch = [];
        $offset = DB::table('patients')->where('facility_id', $facilityId)->count();

        for ($i = 0; $i < $count; $i++) {
            $id = Str::uuid()->toString();
            $sex = ['male', 'female'][array_rand(['male', 'female'])];
            $dob = now()->subYears(rand(1, 90))->subDays(rand(0, 364))->format('Y-m-d');
            $seq = $offset + $i + 1;

            $batch[] = [
                'id' => $id,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'full_name' => 'Patient '.$seq.' Perf',
                'mrn' => 'MRN-'.str_pad($seq, 7, '0', STR_PAD_LEFT),
                'sex' => $sex,
                'date_of_birth' => $dob,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $this->patientIds[] = $id;

            if (count($batch) >= 500) {
                DB::table('patients')->insert($batch);
                $this->inserted += count($batch);
                $batch = [];
            }
        }

        if ($batch) {
            DB::table('patients')->insert($batch);
            $this->inserted += count($batch);
        }
    }

    private function seedEncounters(string $tenantId, string $facilityId, int $count): void
    {
        $now = now()->toDateTimeString();
        $batch = [];
        $statuses = ['open', 'in_progress', 'closed'];
        $types = ['opd', 'er', 'ipd'];

        for ($i = 0; $i < $count; $i++) {
            $id = Str::uuid()->toString();
            $encDate = now()->subDays(rand(0, 365));
            $batch[] = [
                'id' => $id,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $this->patientIds[array_rand($this->patientIds)],
                'provider_staff_id' => $this->staffIds[array_rand($this->staffIds)],
                'type' => $types[array_rand($types)],
                'status' => $statuses[array_rand($statuses)],
                'started_at' => $encDate->format('Y-m-d H:i:s'),
                'ended_at' => $encDate->copy()->addMinutes(rand(10, 120))->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $this->encounterIds[] = $id;

            if (count($batch) >= 500) {
                DB::table('encounters')->insert($batch);
                $this->inserted += count($batch);
                $batch = [];
            }
        }

        if ($batch) {
            DB::table('encounters')->insert($batch);
            $this->inserted += count($batch);
        }
    }

    private function seedAppointments(string $tenantId, string $facilityId, int $count): void
    {
        $now = now()->toDateTimeString();
        $batch = [];
        $statuses = ['booked', 'completed', 'cancelled', 'no_show'];
        $apptTypes = ['opd', 'follow_up', 'procedure', 'teleconsult'];
        $sources = ['counter', 'portal', 'walk_in', 'follow_up'];

        // Deterministic slots to avoid unique constraint violations
        // 200 staff × 8 half-hour slots per day = 1600 appointments/day
        $staffCount = count($this->staffIds);
        $slotsPerDay = 8; // 8:00 to 15:30
        $offset = DB::table('appointments')->where('tenant_id', $tenantId)->where('facility_id', $facilityId)->count();

        for ($i = 0; $i < $count; $i++) {
            $seq = $offset + $i;
            $staffIdx = $seq % $staffCount;
            $dayOffset = intdiv($seq, $staffCount * $slotsPerDay);
            $slotInDay = intdiv($seq, $staffCount) % $slotsPerDay;
            $hour = 8 + $slotInDay;
            $start = now()->subDays(30 + $dayOffset)->setTime($hour, 0);
            $end = $start->copy()->addMinutes(30);
            $batch[] = [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $this->patientIds[array_rand($this->patientIds)],
                'provider_staff_id' => $this->staffIds[$staffIdx],
                'appointment_type' => $apptTypes[array_rand($apptTypes)],
                'source' => $sources[array_rand($sources)],
                'starts_at' => $start->format('Y-m-d H:i:s'),
                'ends_at' => $end->format('Y-m-d H:i:s'),
                'status' => $statuses[array_rand($statuses)],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                DB::table('appointments')->insert($batch);
                $this->inserted += count($batch);
                $batch = [];
            }
        }

        if ($batch) {
            DB::table('appointments')->insert($batch);
            $this->inserted += count($batch);
        }
    }

    private function seedLabOrders(string $tenantId, string $facilityId, int $count): void
    {
        $now = now()->toDateTimeString();
        $batch = [];
        $orderBatch = [];
        $statuses = ['ordered', 'collected', 'processing', 'results_entered', 'verified', 'reported', 'correcting'];

        // Limit to valid subset for seed data
        $validStatuses = ['ordered', 'collected', 'processing', 'reported'];

        // Create dummy lab_test entries first for foreign keys
        $testNames = ['CBC', 'BMP', 'LFT', 'TFT', 'Urinalysis', 'Lipid', 'HbA1c', 'PT', 'ESR', 'CRP'];
        $labTestIds = [];
        foreach ($testNames as $tn) {
            $existingTest = DB::table('lab_tests')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('name', $tn)
                ->first();
            if ($existingTest) {
                $labTestIds[] = $existingTest->id;
            } else {
                $testId = Str::uuid()->toString();
                DB::table('lab_tests')->insert([
                    'id' => $testId,
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'name' => $tn,
                    'code' => strtoupper(str_replace(' ', '', $tn)),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $labTestIds[] = $testId;
                $this->inserted++;
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $orderId = Str::uuid()->toString();

            $orderBatch[] = [
                'id' => $orderId,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $this->patientIds[array_rand($this->patientIds)],
                'encounter_id' => $this->encounterIds[array_rand($this->encounterIds)],
                'ordered_by_staff_id' => $this->staffIds[array_rand($this->staffIds)],
                'status' => $validStatuses[array_rand($validStatuses)],
                'priority' => 'routine',
                'ordered_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            // One test per order to satisfy unique constraint
            $testId = $labTestIds[array_rand($labTestIds)];

            $batch[] = [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'lab_order_id' => $orderId,
                'lab_test_id' => $testId,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                DB::table('lab_orders')->insert($orderBatch);
                DB::table('lab_order_items')->insert($batch);
                $this->inserted += count($batch) * 2;
                $batch = [];
                $orderBatch = [];
            }
        }

        if ($orderBatch) {
            DB::table('lab_orders')->insert($orderBatch);
            DB::table('lab_order_items')->insert($batch);
            $this->inserted += count($batch) * 2;
        }
    }

    private function seedPrescriptions(string $tenantId, string $facilityId, int $count): void
    {
        $now = now()->toDateTimeString();
        $batch = [];
        $lineBatch = [];
        $rxStatuses = ['drafted', 'active', 'dispensed', 'discontinued', 'expired'];
        $frequencies = ['BD', 'TDS', 'QDS', 'OD'];

        for ($i = 0; $i < $count; $i++) {
            $rxId = Str::uuid()->toString();

            $batch[] = [
                'id' => $rxId,
                'tenant_id' => $tenantId,
                'patient_id' => $this->patientIds[array_rand($this->patientIds)],
                'encounter_id' => $this->encounterIds[array_rand($this->encounterIds)],
                'prescriber_staff_id' => $this->staffIds[array_rand($this->staffIds)],
                'status' => $rxStatuses[array_rand($rxStatuses)],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $lines = rand(1, 3);
            for ($l = 0; $l < $lines; $l++) {
                $lineBatch[] = [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'prescription_id' => $rxId,
                    'medication_id' => $this->medicationIds[array_rand($this->medicationIds)],
                    'dose' => rand(1, 5).' tablet(s)',
                    'route' => ['oral', 'iv', 'im', 'sc'][array_rand(['oral', 'iv', 'im', 'sc'])],
                    'frequency' => $frequencies[array_rand($frequencies)],
                    'duration' => rand(3, 30).' days',
                    'quantity_minor' => rand(1, 10) * 100,
                    'line_no' => $l + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($batch) >= 500) {
                DB::table('prescriptions')->insert($batch);
                DB::table('prescription_lines')->insert($lineBatch);
                $this->inserted += count($batch) + count($lineBatch);
                $batch = [];
                $lineBatch = [];
            }
        }

        if ($batch) {
            DB::table('prescriptions')->insert($batch);
            DB::table('prescription_lines')->insert($lineBatch);
            $this->inserted += count($batch) + count($lineBatch);
        }
    }

    private function seedBilling(string $tenantId, string $facilityId, int $count): void
    {
        $now = now()->toDateTimeString();
        $invoiceBatch = [];
        $chargeBatch = [];
        $paymentBatch = [];
        $statuses = ['draft', 'issued', 'partially_paid', 'paid', 'voided'];
        $invNum = DB::table('invoices')->where('tenant_id', $tenantId)->max('invoice_number') ?? 0;

        for ($i = 0; $i < $count; $i++) {
            $invoiceId = Str::uuid()->toString();
            $patientId = $this->patientIds[array_rand($this->patientIds)];
            $total = rand(500, 50000) * 100; // in minor
            $tax = (int) round($total * 0.13);
            $invNum++;
            $status = $statuses[array_rand($statuses)];

            $invoiceBatch[] = [
                'id' => $invoiceId,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $patientId,
                'invoice_number' => $invNum,
                'status' => $status,
                'total_minor' => $total + $tax,
                'total_tax_minor' => $tax,
                'paid_minor' => $status === 'paid' ? $total + $tax : 0,
                'issued_at' => $status !== 'draft' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $chargeBatch[] = [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'patient_id' => $patientId,
                'source_type' => 'encounter',
                'encounter_id' => $this->encounterIds[array_rand($this->encounterIds)],
                'description' => 'Consultation fee',
                'amount_minor' => $total,
                'currency' => 'NPR',
                'tax_rate_bps' => 1300,
                'status' => 'posted',
                'charged_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($status === 'paid' || $status === 'partial') {
                $paymentBatch[] = [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'facility_id' => $facilityId,
                    'patient_id' => $patientId,
                    'method' => ['cash', 'card', 'wallet', 'bank', 'insurance'][array_rand(['cash', 'card', 'wallet', 'bank', 'insurance'])],
                    'amount_minor' => $total + $tax,
                    'currency' => 'NPR',
                    'status' => 'captured',
                    'idempotency_key' => Str::uuid()->toString(),
                    'received_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (count($invoiceBatch) >= 500) {
                DB::table('invoices')->insert($invoiceBatch);
                DB::table('charges')->insert($chargeBatch);
                DB::table('payments')->insert($paymentBatch);
                $this->inserted += count($invoiceBatch) + count($chargeBatch) + count($paymentBatch);
                $invoiceBatch = [];
                $chargeBatch = [];
                $paymentBatch = [];
            }
        }

        if ($invoiceBatch) {
            DB::table('invoices')->insert($invoiceBatch);
            DB::table('charges')->insert($chargeBatch);
            DB::table('payments')->insert($paymentBatch);
            $this->inserted += count($invoiceBatch) + count($chargeBatch) + count($paymentBatch);
        }
    }

    private function seedAuditEvents(string $tenantId, string $facilityId, int $count): void
    {
        $now = now()->toDateTimeString();
        $batch = [];
        $actions = ['create', 'read', 'update', 'delete', 'login', 'export'];

        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'occurred_at' => $now,
                'actor_type' => 'user',
                'actor_email' => 'system@perf.test',
                'action' => $actions[array_rand($actions)],
                'resource_type' => 'Patient',
                'resource_id' => $this->patientIds[array_rand($this->patientIds)],
                'facility_id' => $facilityId,
                'ip_address' => '127.0.0.1',
                'correlation_id' => Str::uuid()->toString(),
            ];

            if (count($batch) >= 500) {
                DB::table('audit_events')->insert($batch);
                $this->inserted += count($batch);
                $batch = [];
            }
        }

        if ($batch) {
            DB::table('audit_events')->insert($batch);
            $this->inserted += count($batch);
        }
    }

    private function seedInventory(string $tenantId, string $facilityId, int $count): void
    {
        $now = now()->toDateTimeString();

        // Create inventory_items for each medication
        $invBatch = [];
        foreach ($this->medicationIds as $medId) {
            $invId = Str::uuid()->toString();
            $invBatch[] = [
                'id' => $invId,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'medication_id' => $medId,
                'quantity_on_hand' => rand(100, 2000),
                'reorder_level' => 50,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($invBatch) >= 500) {
                DB::table('inventory_items')->insert($invBatch);
                $this->inserted += count($invBatch);
                $invBatch = [];
            }
        }
        if ($invBatch) {
            DB::table('inventory_items')->insert($invBatch);
            $this->inserted += count($invBatch);
        }

        // Now create stock_batches referencing inventory_items
        $invItemIds = DB::table('inventory_items')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->pluck('id')
            ->toArray();

        if (empty($invItemIds)) {
            return;
        }

        $batch = [];
        for ($i = 0; $i < $count; $i++) {
            $batch[] = [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'inventory_item_id' => $invItemIds[array_rand($invItemIds)],
                'medication_id' => $this->medicationIds[array_rand($this->medicationIds)],
                'batch_number' => 'BATCH-'.str_pad($i + 1, 6, '0', STR_PAD_LEFT),
                'expiry_date' => now()->addMonths(rand(1, 24))->format('Y-m-d'),
                'quantity_received' => rand(100, 1000),
                'quantity_remaining' => rand(10, 1000),
                'status' => 'available',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= 500) {
                DB::table('stock_batches')->insert($batch);
                $this->inserted += count($batch);
                $batch = [];
            }
        }

        if ($batch) {
            DB::table('stock_batches')->insert($batch);
            $this->inserted += count($batch);
        }
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->info('=== Database Summary ===');

        $tables = [
            'organizations', 'facilities', 'departments', 'staff', 'patients',
            'encounters', 'appointments', 'lab_orders', 'lab_order_items',
            'prescriptions', 'prescription_lines', 'invoices', 'charges',
            'payments', 'audit_events', 'medications', 'stock_batches',
        ];

        foreach ($tables as $table) {
            try {
                $count = DB::table($table)->count();
                $this->line("  {$table}: ".number_format($count));
            } catch (\Throwable) {
                $this->line("  {$table}: N/A");
            }
        }
    }
}
