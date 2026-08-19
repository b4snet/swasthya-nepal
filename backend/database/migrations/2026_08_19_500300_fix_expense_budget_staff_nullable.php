<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 17 fix — make requested_by_staff_id nullable on expenses
 * because the controller may not always resolve a staff record for the
 * requesting user (e.g. platform-level admin without tenant staff).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.expenses ALTER COLUMN requested_by_staff_id DROP NOT NULL');
        DB::statement('ALTER TABLE public.budgets ALTER COLUMN created_by_staff_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.expenses ALTER COLUMN requested_by_staff_id SET NOT NULL');
        DB::statement('ALTER TABLE public.budgets ALTER COLUMN created_by_staff_id SET NOT NULL');
    }
};
