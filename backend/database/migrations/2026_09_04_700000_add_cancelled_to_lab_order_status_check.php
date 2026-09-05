<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'alter table lab_orders drop constraint chk_lab_orders_status'
        );
        DB::statement(
            "alter table lab_orders add constraint chk_lab_orders_status check (status in ('ordered', 'collected', 'processing', 'results_entered', 'verified', 'reported', 'correcting', 'cancelled'))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'alter table lab_orders drop constraint chk_lab_orders_status'
        );
        DB::statement(
            "alter table lab_orders add constraint chk_lab_orders_status check (status in ('ordered', 'collected', 'processing', 'results_entered', 'verified', 'reported', 'correcting'))"
        );
    }
};
