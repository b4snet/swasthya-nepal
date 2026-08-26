<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReidentifyPatients extends Command
{
    protected $signature = 'patients:reidentify {--dry-run}';

    protected $description = 'Re-identify all patient IDs to NBMH-XXXXXXXX format and update all FK references';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // 1. Build the mapping: old UUID → new NBMH-XXXXXXXX
        $this->info('Building ID mapping...');
        $patients = DB::table('patients')->select('id')->orderBy('created_at')->get();
        $count = $patients->count();
        $this->info("Found {$count} patients.");

        $mapping = [];
        $seq = 1;
        foreach ($patients as $p) {
            $newId = 'NBMH-'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT);
            $mapping[$p->id] = $newId;
            $seq++;
        }
        $this->info('Generated mapping for '.count($mapping).' patients.');

        // Show a few samples
        $samples = array_slice($mapping, 0, 5);
        foreach ($samples as $old => $new) {
            $this->line("  {$old} → {$new}");
        }
        $this->line('  ...');

        if ($dryRun) {
            $this->info('DRY RUN — no changes made.');

            return 0;
        }

        // 2. Alter column types from uuid to varchar where needed
        $this->info('Altering column types from uuid to varchar...');
        $fkColumns = DB::select("
            SELECT table_name, column_name 
            FROM information_schema.columns 
            WHERE table_schema = 'public' 
            AND column_name IN ('patient_id', 'merge_into_patient_id')
            ORDER BY table_name
        ");

        // Also check portal_accounts.patient_id
        $allUpdates = [];
        foreach ($fkColumns as $col) {
            $table = $col->table_name;
            $column = $col->column_name;
            $affected = DB::table($table)->whereNotNull($column)->count();
            if ($affected > 0) {
                $allUpdates[] = "{$table}.{$column} ({$affected} rows)";
            }
        }

        // Also patients.merge_into_patient_id if it exists
        if (DB::getSchemaBuilder()->hasColumn('patients', 'merge_into_patient_id')) {
            $mergeCount = DB::table('patients')->whereNotNull('merge_into_patient_id')->count();
            if ($mergeCount > 0) {
                $allUpdates[] = "patients.merge_into_patient_id ({$mergeCount} rows)";
            }
        }

        $this->info('Tables to update:');
        foreach ($allUpdates as $u) {
            $this->line("  - {$u}");
        }

        // 2b. Also include the patients table itself
        $allUpdates[] = 'patients.id ('.$count.' rows)';
        $allUpdates[] = 'patients.merge_into_patient_id';

        // 2c. Find all FK constraints on patient_id columns and drop them
        $this->info('Dropping foreign key constraints...');
        $fkConstraints = DB::select("
            SELECT tc.table_name, tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
            AND kcu.column_name IN ('patient_id', 'merge_into_patient_id')
            ORDER BY tc.table_name
        ");
        $droppedConstraints = [];
        foreach ($fkConstraints as $fk) {
            DB::statement("ALTER TABLE \"{$fk->table_name}\" DROP CONSTRAINT \"{$fk->constraint_name}\"");
            $droppedConstraints[] = $fk->constraint_name;
            $this->line("  Dropped: {$fk->constraint_name}");
        }
        $this->info('Dropped '.count($droppedConstraints).' FK constraints.');

        // 2d. Alter column types from uuid to varchar
        foreach ($fkColumns as $col) {
            $table = $col->table_name;
            $column = $col->column_name;
            try {
                DB::statement("ALTER TABLE \"{$table}\" ALTER COLUMN \"{$column}\" TYPE varchar(36)");
                $this->line("  Altered {$table}.{$column} to varchar(36)");
            } catch (\Throwable $e) {
                $this->line("  Skipped {$table}.{$column}: ".$e->getMessage());
            }
        }
        // Also alter patients.id and patients.merge_into_patient_id
        // First drop ALL FKs that reference patients.id (including ones not in the patient_id column list)
        $allPatientFks = DB::select("
            SELECT tc.table_name, tc.constraint_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu ON tc.constraint_name = ccu.constraint_name AND tc.constraint_schema = ccu.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY' AND tc.table_schema = 'public'
            AND ccu.table_name = 'patients' AND ccu.column_name = 'id'
        ");
        foreach ($allPatientFks as $fk) {
            if (! in_array($fk->constraint_name, $droppedConstraints)) {
                DB::statement("ALTER TABLE \"{$fk->table_name}\" DROP CONSTRAINT \"{$fk->constraint_name}\"");
                $droppedConstraints[] = $fk->constraint_name;
                $this->line("  Dropped extra FK: {$fk->constraint_name}");
            }
        }

        try {
            DB::statement('ALTER TABLE "patients" ALTER COLUMN "id" TYPE varchar(36)');
            $this->line('  Altered patients.id to varchar(36)');
        } catch (\Throwable $e) {
            $this->line('  Skipped patients.id: '.$e->getMessage());
        }
        if (DB::getSchemaBuilder()->hasColumn('patients', 'merge_into_patient_id')) {
            try {
                DB::statement('ALTER TABLE "patients" ALTER COLUMN "merge_into_patient_id" TYPE varchar(36)');
                $this->line('  Altered patients.merge_into_patient_id to varchar(36)');
            } catch (\Throwable $e) {
                $this->line('  Skipped patients.merge_into_patient_id: '.$e->getMessage());
            }
        }

        // 3. Execute in a transaction
        $this->info('Beginning transaction...');

        DB::beginTransaction();
        try {
            // Disable FK checks (PostgreSQL)
            DB::statement('SET session_replication_role = replica;');

            $totalUpdated = 0;

            // 3a. Update each FK column in each table
            foreach ($fkColumns as $col) {
                $table = $col->table_name;
                $column = $col->column_name;

                $this->line("  Updating {$table}.{$column}...");

                // Process in batches to avoid memory issues
                $rows = DB::table($table)
                    ->whereNotNull($column)
                    ->select('id', $column)
                    ->get();

                $updated = 0;
                foreach ($rows as $row) {
                    $oldVal = $row->$column;
                    if (isset($mapping[$oldVal])) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update([$column => $mapping[$oldVal]]);
                        $updated++;
                    }
                }
                $totalUpdated += $updated;
                $this->line("    Updated {$updated} rows in {$table}.{$column}");
            }

            // 3b. Update patients.merge_into_patient_id
            if (DB::getSchemaBuilder()->hasColumn('patients', 'merge_into_patient_id')) {
                $mergeRows = DB::table('patients')
                    ->whereNotNull('merge_into_patient_id')
                    ->select('id', 'merge_into_patient_id')
                    ->get();
                foreach ($mergeRows as $row) {
                    if (isset($mapping[$row->merge_into_patient_id])) {
                        DB::table('patients')
                            ->where('id', $row->id)
                            ->update(['merge_into_patient_id' => $mapping[$row->merge_into_patient_id]]);
                        $totalUpdated++;
                    }
                }
            }

            // 3c. Update patients.id itself (last, since we used it as the row key above)
            $this->info('Updating patients.id...');
            foreach ($mapping as $oldId => $newId) {
                DB::table('patients')->where('id', $oldId)->update(['id' => $newId]);
            }
            $this->info('Updated '.count($mapping).' patient primary keys.');

            // Re-enable FK checks
            DB::statement('SET session_replication_role = origin;');

            DB::commit();
            $this->info("Transaction committed. Total FK updates: {$totalUpdated}");

            // 4. Verify
            $verifyCount = DB::table('patients')->where('id', 'like', 'NBMH-%')->count();
            $this->info("Verification: {$verifyCount} patients now have NBMH- IDs.");

            return 0;
        } catch (\Throwable $e) {
            DB::rollBack();
            DB::statement('SET session_replication_role = origin;');
            $this->error('Transaction rolled back: '.$e->getMessage());

            return 1;
        }
    }
}
