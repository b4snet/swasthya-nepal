<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE numbering_configs DROP CONSTRAINT IF EXISTS numbering_configs_pkey');
        DB::statement('ALTER TABLE numbering_configs DROP COLUMN id');
        DB::statement('ALTER TABLE numbering_configs ADD COLUMN id uuid NOT NULL DEFAULT gen_random_uuid() PRIMARY KEY');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE numbering_configs DROP COLUMN id');
        DB::statement('ALTER TABLE numbering_configs ADD COLUMN id bigserial PRIMARY KEY');
    }
};
