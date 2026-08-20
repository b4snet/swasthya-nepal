<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE public.report_subscriptions ALTER COLUMN staff_id DROP NOT NULL');
        DB::statement('ALTER TABLE public.report_acknowledgments ALTER COLUMN staff_id DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE public.report_subscriptions ALTER COLUMN staff_id SET NOT NULL');
        DB::statement('ALTER TABLE public.report_acknowledgments ALTER COLUMN staff_id SET NOT NULL');
    }
};
