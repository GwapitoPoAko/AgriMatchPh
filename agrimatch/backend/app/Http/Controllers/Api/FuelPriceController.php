<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFuelPriceRequest;
use App\Models\FuelPriceLog;
use App\Services\AuditLogger;
use App\Services\LogisticsPoolingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Weekly DOE pump prices that drive the Dynamic Fuel Surcharge Matrix.
 */
class FuelPriceController extends Controller
{
    public function __construct(
        private readonly LogisticsPoolingService $logistics,
        private readonly AuditLogger $audit,
    ) {
    }

    /** GET /api/v1/fuel-prices?region_code=X&weeks=12 — history + current matrix position. */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'region_code' => ['nullable', 'string', 'max:10', Rule::exists('provinces', 'region_code')],
            'weeks' => ['nullable', 'integer', 'min:1', 'max:52'],
        ]);
        $region = $validated['region_code'] ?? config('agrimatch.fuel.fallback_region');
        $weeks = $validated['weeks'] ?? 12;

        $history = FuelPriceLog::query()
            ->where('region_code', $region)
            ->where('effective_date', '>=', now()->subWeeks($weeks)->toDateString())
            ->orderBy('effective_date')
            ->get(['fuel_type', 'effective_date', 'price_per_liter', 'change_amount'])
            ->groupBy('fuel_type')
            ->map(fn ($rows) => $rows->map(fn ($r) => [
                'date' => $r->effective_date->toDateString(),
                'price' => $r->price_per_liter,
                'change' => $r->change_amount,
            ])->values());

        return response()->json([
            'region_code' => $region,
            'history' => $history,
            'matrix' => $this->logistics->fuelSurchargeMatrix($region),
        ]);
    }

    /** POST /api/v1/fuel-prices (admin) — logs a price and re-prices open truck pools in real time. */
    public function store(StoreFuelPriceRequest $request): JsonResponse
    {
        $data = $request->validated();

        [$log, $repricing] = DB::transaction(function () use ($data, $request) {
            $previous = FuelPriceLog::query()
                ->where('region_code', $data['region_code'])
                ->where('fuel_type', $data['fuel_type'])
                ->where('effective_date', '<', $data['effective_date'])
                ->orderByDesc('effective_date')
                ->value('price_per_liter');

            $log = new FuelPriceLog([
                'region_code' => $data['region_code'],
                'fuel_type' => $data['fuel_type'],
                'effective_date' => $data['effective_date'],
                'price_per_liter' => $data['price_per_liter'],
                'source' => $data['source'] ?? 'DOE Oil Monitor',
            ]);
            $log->forceFill([
                'previous_price' => $previous,
                'change_amount' => $previous !== null ? round($data['price_per_liter'] - $previous, 2) : null,
                'recorded_by_id' => $request->user()->id,
            ])->save();

            $this->audit->record('fuel_price.recorded', $log, [
                'region' => $log->region_code, 'fuel_type' => $log->fuel_type,
                'price_per_liter' => (float) $log->price_per_liter, 'effective_date' => $data['effective_date'],
            ], $previous !== null ? ['price_per_liter' => (float) $previous] : []);

            // Real-time re-pricing of every open pool in that region.
            return [$log, $this->logistics->recalculateOpenPools($log->region_code)];
        });

        return response()->json([
            'data' => $log,
            'repricing' => $repricing,
            'matrix' => $this->logistics->fuelSurchargeMatrix($log->region_code),
        ], 201);
    }
}
