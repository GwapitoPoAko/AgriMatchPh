<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateFreightPoolRequest;
use App\Http\Requests\FreightQuoteRequest;
use App\Http\Requests\JoinFreightPoolRequest;
use App\Models\FreightPool;
use App\Models\HarvestListing;
use App\Models\TradingHub;
use App\Models\User;
use App\Services\LogisticsPoolingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * "Hakot" shared-freight endpoints: calculator, pool list, create / join,
 * status transitions and the dynamic fuel surcharge matrix.
 */
class FreightController extends Controller
{
    public function __construct(private readonly LogisticsPoolingService $logistics)
    {
    }

    /** POST /api/v1/freight/quote — solo vs. joinable pools, best saving first. */
    public function quote(FreightQuoteRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user('sanctum');
        $hub = TradingHub::with('province:id,region_code')->findOrFail($data['trading_hub_id']);

        $shipper = [
            'volume_kg' => isset($data['volume_kg']) ? (float) $data['volume_kg'] : null,
            'lat' => isset($data['pickup_latitude']) ? (float) $data['pickup_latitude'] : null,
            'lng' => isset($data['pickup_longitude']) ? (float) $data['pickup_longitude'] : null,
            'barangay' => $data['barangay'] ?? null,
            'listing_id' => null,
        ];

        if (! empty($data['harvest_listing_id'])) {
            $listing = HarvestListing::findOrFail($data['harvest_listing_id']);
            // Private pickup coordinates are only usable by the listing's own seller.
            abort_unless($user && ($user->id === $listing->seller_id || $user->isAdmin()), 403, 'You can only quote freight for your own listings.');
            abort_if($listing->pickup_latitude === null, 422, 'This listing has no pickup point yet.');
            $shipper = [
                'volume_kg' => $shipper['volume_kg'] ?? $listing->available_volume_kg,
                'lat' => $listing->pickup_latitude,
                'lng' => $listing->pickup_longitude,
                'barangay' => $listing->barangay,
                'listing_id' => $listing->id,
            ];
        }

        return response()->json(['data' => $this->logistics->quoteForShipper($hub, $shipper, $data['departure_date'], $user)]);
    }

    /** GET /api/v1/freight/pools?scope=mine|open&trading_hub_id= */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', Rule::in(['mine', 'open', 'all'])],
            'trading_hub_id' => ['nullable', 'integer', 'exists:trading_hubs,id'],
            'status' => ['nullable', Rule::in(['forming', 'confirmed', 'in_transit', 'delivered', 'cancelled'])],
        ]);
        $user = $request->user();
        $scope = $validated['scope'] ?? 'mine';

        $pools = FreightPool::query()
            ->with(['hub:id,short_name,code', 'driver:id,name', 'organizer:id,name', 'fuelPriceLog:id,fuel_type,price_per_liter,effective_date,region_code'])
            ->withCount('activeMembers')
            ->when($scope === 'mine' && ! $user->isAdmin(), fn ($q) => $q->where(fn ($q) => $q
                ->where('organizer_id', $user->id)
                ->orWhere('driver_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id))))
            ->when($scope === 'open', fn ($q) => $q->open()->where('is_solo', false))
            ->when($validated['trading_hub_id'] ?? null, fn ($q, $h) => $q->where('trading_hub_id', $h))
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderBy('departure_date')
            ->limit(50)
            ->get();

        $mine = $pools->isEmpty() ? collect() : \App\Models\FreightPoolMember::whereIn('freight_pool_id', $pools->pluck('id'))
            ->where('user_id', $user->id)->get()->groupBy('freight_pool_id');

        return response()->json(['data' => $pools->map(fn (FreightPool $p) => [
            'id' => $p->id,
            'reference_code' => $p->reference_code,
            'status' => $p->status,
            'hub' => $p->hub?->short_name,
            'origin' => $p->origin_municipality,
            'departure_date' => $p->departure_date->toDateString(),
            'vehicle_class' => $p->vehicle_class,
            'vehicle_label' => config("agrimatch.logistics.vehicles.{$p->vehicle_class}.label"),
            'truck_count' => $p->truck_count,
            'members' => $p->active_members_count,
            'total_volume_kg' => $p->total_volume_kg,
            'capacity_kg' => $p->capacity_kg,
            'utilization_pct' => $p->capacity_kg > 0 ? round($p->total_volume_kg / $p->capacity_kg * 100, 1) : 0,
            'route_distance_km' => $p->route_distance_km,
            'is_solo' => (bool) $p->is_solo,
            'base_cost' => $p->base_cost,
            'fuel_surcharge_pct' => $p->fuel_surcharge_pct,
            'fuel_surcharge_amount' => $p->fuel_surcharge_amount,
            'fuel_surcharge_label' => $p->fuel_surcharge_pct != 0
                ? sprintf('%s%s%% fuel surcharge applied', $p->fuel_surcharge_pct > 0 ? '+' : '−', rtrim(rtrim(number_format(abs($p->fuel_surcharge_pct), 2), '0'), '.'))
                : null,
            'fuel' => $p->fuelPriceLog?->only(['fuel_type', 'price_per_liter', 'region_code']) + ['effective_date' => $p->fuelPriceLog?->effective_date?->toDateString()],
            'total_cost' => $p->total_cost,
            'driver' => $p->driver?->name,
            'organizer' => $p->organizer?->name,
            'your_share' => isset($mine[$p->id]) ? round($mine[$p->id]->sum('cost_share'), 2) : null,
            'your_savings' => isset($mine[$p->id]) ? round($mine[$p->id]->sum('savings'), 2) : null,
            'last_recalculated_at' => $p->last_recalculated_at?->toIso8601String(),
        ])]);
    }

    /** GET /api/v1/freight/pools/{freightPool} — full cost breakdown. */
    public function show(Request $request, FreightPool $freightPool): JsonResponse
    {
        Gate::authorize('view', $freightPool);
        $freightPool->load(['hub.province', 'activeMembers.user.profile:id,user_id,business_name', 'activeMembers.listing.province:id,name', 'driver:id,name,phone', 'fuelPriceLog']);
        $viewer = $request->user();

        $quote = $this->logistics->quote($freightPool->hub, $freightPool->activeMembers->map(fn ($m) => [
            'key' => "m{$m->id}",
            'label' => $viewer->id === $m->user_id || $viewer->isAdmin() || $viewer->id === $freightPool->organizer_id
                ? ($m->user?->profile?->business_name ?? $m->user?->name)
                : "Farmer in Brgy. {$m->pickup_barangay}",
            'user_id' => $m->user_id,
            'listing_id' => $m->harvest_listing_id,
            'barangay' => $m->pickup_barangay,
            'municipality' => $m->listing?->municipality,
            'province' => $m->listing?->province?->name,
            'volume_kg' => $m->volume_kg,
            'lat' => $m->pickup_latitude,
            'lng' => $m->pickup_longitude,
            'is_you' => $viewer->id === $m->user_id,
        ])->all(), $freightPool->departure_date);

        return response()->json(['data' => [
            'pool' => $freightPool->only(['id', 'reference_code', 'status', 'origin_municipality', 'truck_count', 'last_recalculated_at'])
                + ['departure_date' => $freightPool->departure_date->toDateString(), 'driver' => $freightPool->driver?->only(['id', 'name'])],
            'quote' => $quote,
            'fuel' => $this->logistics->fuelSurchargeMatrix($freightPool->hub->regionCode(), $quote['cost']['distance_band'], $freightPool->departure_date),
        ]]);
    }

    /** POST /api/v1/freight/pools */
    public function store(CreateFreightPoolRequest $request): JsonResponse
    {
        $data = $request->validated();
        $hub = TradingHub::with('province')->findOrFail($data['trading_hub_id']);
        $pool = $this->logistics->createPool($request->user(), $hub, $data['departure_date'], $data['loads'], (bool) ($data['solo'] ?? false));

        return response()->json(['data' => $pool], 201);
    }

    /** POST /api/v1/freight/pools/{freightPool}/join */
    public function join(JoinFreightPoolRequest $request, FreightPool $freightPool): JsonResponse
    {
        $data = $request->validated();
        $listing = HarvestListing::findOrFail($data['harvest_listing_id']);
        $pool = $this->logistics->join($freightPool, $listing, (float) $data['volume_kg'], $request->user());

        return response()->json(['data' => $pool]);
    }

    /** PATCH /api/v1/freight/pools/{freightPool}/status */
    public function updateStatus(Request $request, FreightPool $freightPool): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'in_transit', 'delivered', 'cancelled'])],
            'driver_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', User::ROLE_DRIVER)->where('status', 'active')],
        ]);
        Gate::authorize('updateStatus', [$freightPool, $validated['status']]);

        $pool = $this->logistics->transition($freightPool, $validated['status'], $request->user(), $validated['driver_id'] ?? null);

        return response()->json(['data' => $pool]);
    }
}
