<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('quality_inspections', 'image_url')) {
            Schema::table('quality_inspections', function (Blueprint $table) {
                $table->string('image_url', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('quality_inspections', 'image_url')) {
            Schema::table('quality_inspections', fn (Blueprint $table) => $table->dropColumn('image_url'));
        }
    }
};
