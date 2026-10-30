<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Database-backed operator controls, with environment config as the safe fallback. */
final class FuelPolicy
{
    public static function baseline(string $fuelType): float
    {
        $fallback = (float) config("agrimatch.fuel.baseline_price.{$fuelType}", 0);
        if ($fuelType !== 'diesel') {
            return $fallback;
        }

        return self::number('fuel_baseline_diesel', $fallback);
    }

    public static function surchargeMultiplier(): float
    {
        return self::number('fuel_surcharge_multiplier', 1.0);
    }

    public static function number(string $key, float $fallback): float
    {
        $value = Cache::remember("platform-setting:{$key}", 60, function () use ($key) {
            return Schema::hasTable('platform_settings')
                ? DB::table('platform_settings')->where('key', $key)->value('value')
                : null;
        });

        return is_numeric($value) ? (float) $value : $fallback;
    }

    public static function store(string $key, float $value): void
    {
        $now = now();
        $query = DB::table('platform_settings')->where('key', $key);
        if ($query->exists()) {
            $query->update(['value' => (string) $value, 'updated_at' => $now]);
        } else {
            DB::table('platform_settings')->insert(['key' => $key, 'value' => (string) $value, 'created_at' => $now, 'updated_at' => $now]);
        }
        Cache::forget("platform-setting:{$key}");
    }
}
