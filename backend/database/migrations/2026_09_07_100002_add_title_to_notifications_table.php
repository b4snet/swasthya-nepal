<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('notifications', 'title')) {
                $table->text('title')->nullable()->after('channel');
            }
            if (!Schema::hasColumn('notifications', 'body')) {
                $table->text('body')->nullable()->after('title');
            }
            if (!Schema::hasColumn('notifications', 'link')) {
                $table->text('link')->nullable()->after('body');
            }
            if (!Schema::hasColumn('notifications', 'read')) {
                $table->boolean('read')->default(false)->after('link');
            }
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropColumn(['title', 'body', 'link', 'read']);
        });
    }
};
