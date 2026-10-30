<?php

namespace App\Support;

/**
 * Small geodesy helpers shared by the matching engine, the logistics
 * pooling calculator and the price index (freight normalisation).
 */
final class Geo
{
    private const EARTH_RADIUS_KM = 6371.0;

    public static function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }

    /** Straight-line distance inflated to an approximate road distance. */
    public static function roadKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return self::haversineKm($lat1, $lng1, $lat2, $lng2) * (float) config('agrimatch.logistics.road_factor', 1.35);
    }
}
