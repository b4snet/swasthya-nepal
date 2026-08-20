<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 128);
            $table->text('description')->nullable();
            $table->string('domain', 64);
            $table->string('category', 64)->default('clinical');
            $table->boolean('is_core')->default(false);
            $table->boolean('is_active')->default(true);
            $table->jsonb('dependencies')->default('[]');
            $table->jsonb('required_permissions')->default('[]');
            $table->jsonb('nav_config')->default('{}');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
