<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('freight_pools', 'is_solo')) {
            Schema::table('freight_pools', function (Blueprint $table) {
                $table->boolean('is_solo')->default(false)->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('freight_pools', 'is_solo')) {
            Schema::table('freight_pools', fn (Blueprint $table) => $table->dropColumn('is_solo'));
        }
    }
};
