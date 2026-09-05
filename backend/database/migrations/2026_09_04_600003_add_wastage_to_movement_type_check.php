<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('pgsql')->statement("
            ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS chk_inventory_movements_type;
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE inventory_movements ADD CONSTRAINT chk_inventory_movements_type
                CHECK (movement_type IN ('receipt', 'adjustment', 'dispense', 'return', 'transfer', 'wastage'));
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement("
            ALTER TABLE inventory_movements DROP CONSTRAINT IF EXISTS chk_inventory_movements_type;
        ");
        DB::connection('pgsql')->statement("
            ALTER TABLE inventory_movements ADD CONSTRAINT chk_inventory_movements_type
                CHECK (movement_type IN ('receipt', 'adjustment', 'dispense', 'return', 'transfer'));
        ");
    }
};
