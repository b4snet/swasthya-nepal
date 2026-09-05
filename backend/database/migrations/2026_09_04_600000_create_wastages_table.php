<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->create('wastages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('facility_id');
            $table->uuid('medication_id');
            $table->uuid('inventory_item_id');
            $table->uuid('stock_batch_id')->nullable();
            $table->string('batch_number')->nullable();
            $table->date('batch_expires_at')->nullable();
            $table->integer('quantity_minor');
            $table->string('reason_code');
            $table->text('reason_note')->nullable();
            $table->uuid('wasted_by_staff_id');
            $table->uuid('witness_staff_id')->nullable();
            $table->timestamp('wasted_at');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'facility_id', 'medication_id']);
            $table->index(['tenant_id', 'medication_id']);
            $table->index(['tenant_id', 'stock_batch_id']);
        });

        DB::connection('pgsql')->statement('alter table wastages enable row level security');
        DB::connection('pgsql')->statement('alter table wastages force row level security');

        DB::connection('pgsql')->unprepared("
            CREATE POLICY tenant_facility_isolation_wastages ON wastages
                USING (tenant_id = current_setting('app.tenant_id')::uuid)
                WITH CHECK (tenant_id = current_setting('app.tenant_id')::uuid);
        ");
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('wastages');
    }
};
