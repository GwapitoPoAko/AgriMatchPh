<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CropVariety;
use App\Models\Province;
use App\Models\RegionalPriceIndex;
use App\Services\PriceIndexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Serves the regional price index in shapes that drop straight into Chart.js:
 * a shared `labels` axis of ISO dates and one aligned `data` array per series
 * (null where no observation exists, so lines break instead of lying).
 *
 * All windows are anchored to the latest date that actually has data, so the
 * charts never render empty just because today's survey hasn't been loaded yet.
 */
class PriceIndexController extends Controller
{
    private const RANGES = ['7d' => 7, '14d' => 14, '30d' => 30, '60d' => 60, '90d' => 90, '120d' => 120, '1y' => 365];
    private const CACHE_TTL = 600;

    /**
     * GET /api/v1/price-index/trends
     *
     * crop_variety_id  int|csv   one or more varieties (max 6) — required
     * province_id      int|csv   restrict to provinces (default: all)
     * range            7d|14d|30d|60d|90d|120d|1y (default 30d) — ignored if from/to given
     * from, to         Y-m-d
     * metric           farmgate|wholesale|retail (default farmgate)
     * group_by         variety|province (default variety; province requires exactly one variety)
     */
    public function trends(Request $request): JsonResponse
    {
        $request->merge($this->csvToArray($request, ['crop_variety_id', 'province_id']));

        $validated = $request->validate([
            'crop_variety_id' => ['required', 'array', 'min:1', 'max:6'],
            'crop_variety_id.*' => ['integer', 'exists:crop_varieties,id'],
            'province_id' => ['nullable', 'array', 'max:12'],
            'province_id.*' => ['integer', 'exists:provinces,id'],
            'range' => ['nullable', Rule::in(array_keys(self::RANGES))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'metric' => ['nullable', Rule::in(array_keys(RegionalPriceIndex::METRICS))],
            'group_by' => ['nullable', Rule::in(['variety', 'province'])],
        ]);

        $groupBy = $validated['group_by'] ?? 'variety';
        if ($groupBy === 'province' && count($validated['crop_variety_id']) !== 1) {
            return response()->json(['message' => 'group_by=province requires exactly one crop_variety_id.'], 422);
        }

        $metric = $validated['metric'] ?? 'farmgate';
        $column = RegionalPriceIndex::METRICS[$metric];
        [$from, $to, $latest] = $this->resolveWindow($validated);

        if (! $latest) {
            return response()->json(['meta' => ['message' => 'No price data available yet.'], 'labels' => [], 'datasets' => []]);
        }

        $cacheKey = 'price-trends:' . md5(json_encode([$validated, $from, $to, $metric, $groupBy]));

        $payload = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($validated, $groupBy, $column, $from, $to, $metric, $latest) {
            $seriesKey = $groupBy === 'variety' ? 'crop_variety_id' : 'province_id';

            $rows = RegionalPriceIndex::query()
                ->select([
                    "{$seriesKey} as series_id",
                    'price_date',
                    DB::raw("ROUND(AVG({$column}), 2) AS price"),
                    DB::raw('ROUND(MIN(min_price), 2) AS low'),
                    DB::raw('ROUND(MAX(max_price), 2) AS high'),
                    DB::raw('ROUND(SUM(volume_traded_kg), 0) AS volume'),
                ])
                ->whereIn('crop_variety_id', $validated['crop_variety_id'])
                ->when(! empty($validated['province_id']), fn ($q) => $q->whereIn('province_id', $validated['province_id']))
                ->whereBetween('price_date', [$from, $to])
                ->groupBy($seriesKey, 'price_date')
                ->orderBy('price_date')
                ->get()
                ->groupBy('series_id');

            $labels = collect(CarbonPeriod::create($from, $to))->map(fn (\Carbon\CarbonInterface $d) => $d->toDateString())->values();

            $names = $groupBy === 'variety'
                ? CropVariety::whereIn('id', $rows->keys())->pluck('name', 'id')
                : Province::whereIn('id', $rows->keys())->pluck('name', 'id');

            $datasets = $rows->map(function (Collection $series, $id) use ($labels, $names) {
                $byDate = $series->keyBy(fn ($r) => Carbon::parse($r->price_date)->toDateString());
                $pick = fn (string $field) => $labels->map(fn ($d) => isset($byDate[$d]) ? (float) $byDate[$d]->{$field} : null)->values();

                $data = $pick('price');

                return [
                    'id' => (int) $id,
                    'label' => $names[$id] ?? "Series {$id}",
                    'data' => $data,
                    'low' => $pick('low'),
                    'high' => $pick('high'),
                    'volume' => $pick('volume'),
                    'summary' => $this->summarize($data->filter(fn ($v) => $v !== null)->values()),
                ];
            })->values();

            return [
                'meta' => [
                    'metric' => $metric,
                    'group_by' => $groupBy,
                    'from' => $from,
                    'to' => $to,
                    'latest_available_date' => $latest,
                    'currency' => 'PHP',
                    'unit' => 'kg',
                ],
                'labels' => $labels,
                'datasets' => $datasets,
            ];
        });

        return response()->json($payload);
    }

    /**
     * GET /api/v1/price-index/ticker
     * Latest national-average farm-gate price per variety with day-over-day and
     * 7-day change plus a 14-point sparkline — feeds the dashboard micro-trend cards.
     */
    public function ticker(Request $request): JsonResponse
    {
        $request->merge($this->csvToArray($request, ['crop_variety_id', 'province_id']));
        $validated = $request->validate([
            'crop_variety_id' => ['nullable', 'array', 'max:30'],
            'crop_variety_id.*' => ['integer', 'exists:crop_varieties,id'],
            'province_id' => ['nullable', 'array'],
            'province_id.*' => ['integer', 'exists:provinces,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:30'],
        ]);

        $latest = RegionalPriceIndex::max('price_date');
        if (! $latest) {
            return response()->json(['as_of' => null, 'data' => []]);
        }

        $cacheKey = 'price-ticker:' . md5(json_encode([$validated, $latest]));

        $payload = Cache::remember($cacheKey, self::CACHE_TTL, function () use ($validated, $latest) {
            $from = Carbon::parse($latest)->subDays(13)->toDateString();

            $rows = RegionalPriceIndex::query()
                ->select('crop_variety_id', 'price_date', DB::raw('ROUND(AVG(farmgate_price), 2) AS price'), DB::raw('SUM(volume_traded_kg) AS volume'))
                ->when(! empty($validated['crop_variety_id']), fn ($q) => $q->whereIn('crop_variety_id', $validated['crop_variety_id']))
                ->when(! empty($validated['province_id']), fn ($q) => $q->whereIn('province_id', $validated['province_id']))
                ->whereBetween('price_date', [$from, $latest])
                ->groupBy('crop_variety_id', 'price_date')
                ->orderBy('price_date')
                ->get()
                ->groupBy('crop_variety_id');

            $varieties = CropVariety::with('category:id,name')
                ->whereIn('id', $rows->keys())
                ->get(['id', 'crop_category_id', 'name', 'local_name'])
                ->keyBy('id');

            $items = $rows->map(function (Collection $series, $varietyId) use ($varieties) {
                $prices = $series->pluck('price')->map(fn ($p) => (float) $p)->values();
                $last = $prices->last();
                $prev = $prices->count() > 1 ? $prices[$prices->count() - 2] : null;
                $weekAgo = $prices->count() > 7 ? $prices[$prices->count() - 8] : $prices->first();
                $variety = $varieties[$varietyId] ?? null;

                return [
                    'crop_variety_id' => (int) $varietyId,
                    'name' => $variety?->name,
                    'local_name' => $variety?->local_name,
                    'category' => $variety?->category?->name,
                    'price' => $last,
                    'change_1d' => $prev ? round($last - $prev, 2) : null,
                    'change_1d_pct' => $prev ? round(($last / $prev - 1) * 100, 2) : null,
                    'change_7d_pct' => $weekAgo ? round(($last / $weekAgo - 1) * 100, 2) : null,
                    'volume_14d_kg' => round((float) $series->sum('volume'), 0),
                    'sparkline' => $prices,
                ];
            })
                // Biggest weekly movers first — that's what traders scan for.
                ->sortByDesc(fn ($i) => abs($i['change_7d_pct'] ?? 0))
                ->values();

            return $items;
        });

        $limit = $validated['limit'] ?? 12;

        return response()->json(['as_of' => $latest, 'data' => $payload->take($limit)->values()]);
    }

    /**
     * GET /api/v1/price-index/regional?crop_variety_id=1
     * 7-day average per province for one variety — for regional comparison bars / maps.
     */
    public function regional(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'crop_variety_id' => ['required', 'integer', 'exists:crop_varieties,id'],
            'metric' => ['nullable', Rule::in(array_keys(RegionalPriceIndex::METRICS))],
        ]);

        $column = RegionalPriceIndex::METRICS[$validated['metric'] ?? 'farmgate'];
        $latest = RegionalPriceIndex::where('crop_variety_id', $validated['crop_variety_id'])->max('price_date');

        if (! $latest) {
            return response()->json(['as_of' => null, 'data' => []]);
        }

        $weekStart = Carbon::parse($latest)->subDays(6)->toDateString();
        $prevStart = Carbon::parse($latest)->subDays(13)->toDateString();
        $prevEnd = Carbon::parse($latest)->subDays(7)->toDateString();

        $rows = RegionalPriceIndex::query()
            ->join('provinces as p', 'p.id', '=', 'regional_price_index.province_id')
            ->where('regional_price_index.crop_variety_id', $validated['crop_variety_id'])
            ->whereBetween('regional_price_index.price_date', [$prevStart, $latest])
            ->groupBy('p.id', 'p.name', 'p.region', 'p.latitude', 'p.longitude')
            // $column comes from the RegionalPriceIndex::METRICS allow-list; dates are bound parameters.
            ->select(['p.id', 'p.name', 'p.region', 'p.latitude', 'p.longitude'])
            ->selectRaw("ROUND(AVG(CASE WHEN price_date >= ? THEN {$column} END), 2) AS avg_price", [$weekStart])
            ->selectRaw("ROUND(AVG(CASE WHEN price_date BETWEEN ? AND ? THEN {$column} END), 2) AS prev_avg_price", [$prevStart, $prevEnd])
            ->selectRaw('ROUND(SUM(CASE WHEN price_date >= ? THEN volume_traded_kg ELSE 0 END), 0) AS volume_kg', [$weekStart])
            ->orderBy('avg_price')
            ->get()
            ->map(fn ($r) => [
                'province_id' => (int) $r->id,
                'province' => $r->name,
                'region' => $r->region,
                'latitude' => (float) $r->latitude,
                'longitude' => (float) $r->longitude,
                'avg_price' => $r->avg_price !== null ? (float) $r->avg_price : null,
                'change_pct' => $r->avg_price && $r->prev_avg_price
                    ? round(($r->avg_price / $r->prev_avg_price - 1) * 100, 2)
                    : null,
                'volume_kg' => (float) $r->volume_kg,
            ]);

        $national = $rows->avg('avg_price');

        return response()->json([
            'as_of' => $latest,
            'national_avg' => $national ? round($national, 2) : null,
            'data' => $rows->map(fn ($r) => $r + [
                'vs_national_pct' => $national && $r['avg_price'] ? round(($r['avg_price'] / $national - 1) * 100, 2) : null,
            ])->values(),
        ]);
    }

    /**
     * GET /api/v1/price-index/hubs?crop_variety_id=1&hub_id=1,8&range=30d
     * Daily weighted-average price per trading hub (verified contracts + official feeds).
     */
    public function hubs(Request $request, PriceIndexService $service): JsonResponse
    {
        $request->merge($this->csvToArray($request, ['hub_id']));
        $validated = $request->validate([
            'crop_variety_id' => ['required', 'integer', 'exists:crop_varieties,id'],
            'hub_id' => ['nullable', 'array', 'max:8'],
            'hub_id.*' => ['integer', 'exists:trading_hubs,id'],
            'range' => ['nullable', Rule::in(['7d', '14d', '30d', '60d', '90d'])],
        ]);
        $days = self::RANGES[$validated['range'] ?? '30d'];

        $payload = Cache::remember('hub-trends:' . md5(json_encode($validated) . $service->latestDate()), self::CACHE_TTL,
            fn () => $service->hubTrends($validated['crop_variety_id'], $validated['hub_id'] ?? [], $days));

        return response()->json($payload);
    }

    /**
     * GET /api/v1/price-index/signals?limit=8&all=0
     * Current market spikes / drops across hubs — powers listing and dashboard warnings.
     */
    public function signals(Request $request, PriceIndexService $service): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'all' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'as_of' => $service->latestDate(),
            'thresholds' => config('agrimatch.price_index.signals'),
            'data' => $service->marketSignals($validated['limit'] ?? 8, ! ($validated['all'] ?? false)),
        ]);
    }

    /**
     * POST /api/v1/price-index/recompute (admin) — rebuild the hub index for a date range.
     */
    public function recompute(Request $request, PriceIndexService $service): JsonResponse
    {
        Gate::authorize('recompute-price-index');
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:today'],
            'trading_hub_id' => ['nullable', 'integer', 'exists:trading_hubs,id'],
            'crop_variety_id' => ['nullable', 'integer', 'exists:crop_varieties,id'],
        ]);
        abort_if(Carbon::parse($validated['from'])->diffInDays(Carbon::parse($validated['to'])) > 120, 422, 'Recompute at most 120 days per request.');

        $rows = $service->recomputeRange($validated['from'], $validated['to'], $validated['trading_hub_id'] ?? null, $validated['crop_variety_id'] ?? null);

        return response()->json(['rows_written' => $rows, 'as_of' => $service->latestDate()]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [from, to, latestAvailable]
     */
    private function resolveWindow(array $validated): array
    {
        $latest = RegionalPriceIndex::whereIn('crop_variety_id', $validated['crop_variety_id'])->max('price_date');
        if (! $latest) {
            return [null, null, null];
        }

        $latest = Carbon::parse($latest);
        $to = isset($validated['to']) ? Carbon::parse($validated['to'])->min($latest) : $latest;

        if (isset($validated['from'])) {
            $from = Carbon::parse($validated['from']);
        } else {
            $days = self::RANGES[$validated['range'] ?? '30d'];
            $from = $to->copy()->subDays($days - 1);
        }

        if ($from->greaterThan($to)) {
            $from = $to->copy();
        }

        return [$from->toDateString(), $to->toDateString(), $latest->toDateString()];
    }

    private function summarize(Collection $values): array
    {
        if ($values->isEmpty()) {
            return ['latest' => null, 'first' => null, 'change' => null, 'change_pct' => null, 'high' => null, 'low' => null, 'avg' => null, 'volatility_pct' => null];
        }

        $first = $values->first();
        $latest = $values->last();

        $returns = [];
        for ($i = 1; $i < $values->count(); $i++) {
            if ($values[$i - 1] > 0) {
                $returns[] = $values[$i] / $values[$i - 1] - 1;
            }
        }
        $volatility = null;
        if (count($returns) > 1) {
            $mean = array_sum($returns) / count($returns);
            $variance = array_sum(array_map(fn ($r) => ($r - $mean) ** 2, $returns)) / (count($returns) - 1);
            $volatility = round(sqrt($variance) * 100, 2);
        }

        return [
            'latest' => $latest,
            'first' => $first,
            'change' => round($latest - $first, 2),
            'change_pct' => $first > 0 ? round(($latest / $first - 1) * 100, 2) : null,
            'high' => $values->max(),
            'low' => $values->min(),
            'avg' => round($values->avg(), 2),
            'volatility_pct' => $volatility,
        ];
    }

    private function csvToArray(Request $request, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $request->query($key);
            if (is_string($value) && $value !== '') {
                $out[$key] = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
            } elseif (is_numeric($value)) {
                $out[$key] = [(string) $value];
            }
        }

        return $out;
    }
}
