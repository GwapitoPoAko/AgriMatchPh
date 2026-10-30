<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Add only new nullable location columns and the settings table. */
    public function up(): void
    {
        if (Schema::hasTable('harvest_listings')) {
            Schema::table('harvest_listings', function (Blueprint $table) {
                if (! Schema::hasColumn('harvest_listings', 'latitude')) {
                    $table->decimal('latitude', 10, 7)->nullable();
                }
                if (! Schema::hasColumn('harvest_listings', 'longitude')) {
                    $table->decimal('longitude', 10, 7)->nullable();
                }
            });
            if (Schema::hasColumn('harvest_listings', 'pickup_latitude') && Schema::hasColumn('harvest_listings', 'latitude')) {
                DB::table('harvest_listings')->whereNull('latitude')->whereNotNull('pickup_latitude')->update(['latitude' => DB::raw('pickup_latitude')]);
            }
            if (Schema::hasColumn('harvest_listings', 'pickup_longitude') && Schema::hasColumn('harvest_listings', 'longitude')) {
                DB::table('harvest_listings')->whereNull('longitude')->whereNotNull('pickup_longitude')->update(['longitude' => DB::raw('pickup_longitude')]);
            }
        }

        if (Schema::hasTable('user_profiles')) {
            Schema::table('user_profiles', function (Blueprint $table) {
                if (! Schema::hasColumn('user_profiles', 'latitude')) {
                    $table->decimal('latitude', 10, 7)->nullable();
                }
                if (! Schema::hasColumn('user_profiles', 'longitude')) {
                    $table->decimal('longitude', 10, 7)->nullable();
                }
            });
        }

        if (! Schema::hasTable('platform_settings')) {
            Schema::create('platform_settings', function (Blueprint $table) {
                $table->string('key', 100)->primary();
                $table->string('value', 100);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
        foreach (['harvest_listings', 'user_profiles'] as $name) {
            if (! Schema::hasTable($name)) {
                continue;
            }
            Schema::table($name, function (Blueprint $table) use ($name) {
                $columns = array_values(array_filter(['latitude', 'longitude'], fn ($column) => Schema::hasColumn($name, $column)));
                if ($columns) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
