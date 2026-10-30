<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CropCategory;
use App\Models\Cooperative;
use App\Models\Province;
use App\Models\TradingHub;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * Small, cacheable reference data for filter dropdowns.
 */
class LookupController extends Controller
{
    public function provinces(): JsonResponse
    {
        $data = Cache::remember('lookup:provinces', 3600, fn () => Province::orderBy('region_code')->orderBy('name')
            ->get(['id', 'name', 'region', 'region_code', 'latitude', 'longitude']));

        return response()->json(['data' => $data]);
    }

    public function crops(): JsonResponse
    {
        $data = Cache::remember('lookup:crops', 3600, fn () => CropCategory::orderBy('sort_order')
            ->with(['varieties' => fn ($q) => $q->active()->orderBy('name')
                ->select('id', 'crop_category_id', 'name', 'slug', 'local_name', 'shelf_life_days')])
            ->get(['id', 'name', 'slug', 'icon', 'color_hex']));

        return response()->json(['data' => $data]);
    }

    public function tradingHubs(): JsonResponse
    {
        $data = Cache::remember('lookup:trading-hubs', 3600, fn () => TradingHub::active()
            ->with('province:id,name,region_code')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'short_name', 'province_id', 'municipality', 'latitude', 'longitude', 'hub_type', 'operating_hours'])
            ->map(fn (TradingHub $h) => $h->only(['id', 'code', 'name', 'short_name', 'municipality', 'latitude', 'longitude', 'hub_type', 'operating_hours'])
                + ['province' => $h->province?->name, 'region_code' => $h->province?->region_code]));

        return response()->json(['data' => $data]);
    }

    public function cooperatives(): JsonResponse
    {
        $data = Cache::remember('lookup:cooperatives', 3600, fn () => Cooperative::with('province:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'cda_registration_no', 'province_id', 'municipality', 'member_count', 'primary_crops', 'is_cda_accredited']));

        return response()->json(['data' => $data]);
    }

    public function vehicles(): JsonResponse
    {
        return response()->json(['data' => collect(config('agrimatch.logistics.vehicles'))
            ->map(fn ($v, $k) => ['key' => $k, 'label' => $v['label'], 'capacity_kg' => $v['capacity_kg'], 'fuel' => $v['fuel']])
            ->values()]);
    }
}
