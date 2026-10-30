<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateCoopPoolRequest;
use App\Http\Resources\HarvestListingResource;
use App\Models\Cooperative;
use App\Models\HarvestListing;
use App\Services\CoopPoolingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CoopPoolController extends Controller
{
    public function __construct(private readonly CoopPoolingService $pooling)
    {
    }

    /** GET /api/v1/coop-pools?cooperative_id= — open pools (public market view). */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'cooperative_id' => ['nullable', 'integer', 'exists:cooperatives,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return response()->json(['data' => $this->pooling->activePools($validated['cooperative_id'] ?? null, $validated['limit'] ?? 10)]);
    }

    /**
     * GET /api/v1/cooperatives/{cooperative}/pool-candidates[?crop_variety_id=]
     * Manager only. Without a crop: which crops have ≥ 1 poolable member lot.
     * With a crop: the individual lots that can be committed to a new pool.
     */
    public function candidates(Request $request, Cooperative $cooperative): JsonResponse
    {
        Gate::authorize('managePools', $cooperative);
        $validated = $request->validate(['crop_variety_id' => ['nullable', 'integer', 'exists:crop_varieties,id']]);

        $coopMeta = $cooperative->only(['id', 'name', 'municipality', 'member_count', 'coop_fee_pct', 'cda_registration_no']);

        if (empty($validated['crop_variety_id'])) {
            $crops = HarvestListing::query()
                ->tradable()
                ->join('crop_varieties as v', 'v.id', '=', 'harvest_listings.crop_variety_id')
                ->where('harvest_listings.is_pool_parent', false)
                ->whereNull('harvest_listings.parent_listing_id')
                ->whereIn('harvest_listings.seller_id', $cooperative->memberProfiles()->select('user_id'))
                ->groupBy('v.id', 'v.name', 'v.local_name')
                ->orderByRaw('COUNT(*) DESC')
                ->get([
                    'v.id', 'v.name', 'v.local_name',
                    DB::raw('COUNT(*) AS lots'),
                    DB::raw('COUNT(DISTINCT harvest_listings.seller_id) AS farmers'),
                    DB::raw('SUM(harvest_listings.available_volume_kg) AS available_kg'),
                ])
                ->map(fn ($r) => [
                    'crop_variety_id' => (int) $r->id,
                    'name' => $r->name,
                    'local_name' => $r->local_name,
                    'lots' => (int) $r->lots,
                    'farmers' => (int) $r->farmers,
                    'available_kg' => round((float) $r->available_kg, 2),
                    'poolable' => (int) $r->lots >= (int) config('agrimatch.coop_pool.min_members'),
                ]);

            return response()->json(['data' => $crops, 'meta' => ['cooperative' => $coopMeta, 'min_members' => config('agrimatch.coop_pool.min_members')]]);
        }

        $lots = $this->pooling->candidates($cooperative, $validated['crop_variety_id']);

        return response()->json([
            'data' => $lots->map(fn (HarvestListing $l) => [
                'id' => $l->id,
                'reference_code' => $l->reference_code,
                'farmer' => $l->seller?->profile?->business_name ?? $l->seller?->name,
                'barangay' => $l->barangay,
                'quality_grade' => $l->quality_grade,
                'certified_grade' => $l->certified_grade,
                'is_verified' => $l->is_verified,
                'available_volume_kg' => $l->available_volume_kg,
                'unit_price' => $l->unit_price,
                'harvest_date_start' => $l->harvest_date_start->toDateString(),
                'harvest_date_end' => $l->harvest_date_end->toDateString(),
                'status' => $l->status,
                'trading_hub' => $l->tradingHub?->short_name,
            ]),
            'meta' => [
                'cooperative' => $coopMeta,
                'min_members' => config('agrimatch.coop_pool.min_members'),
                'total_available_kg' => round($lots->sum('available_volume_kg'), 2),
            ],
        ]);
    }

    /** POST /api/v1/coop-pools — aggregate member lots into one parent listing. */
    public function store(CreateCoopPoolRequest $request): JsonResponse
    {
        $data = $request->validated();
        $coop = Cooperative::findOrFail($data['cooperative_id']);

        $parent = $this->pooling->createPool($request->user(), $coop, $data);
        $parent->load(['variety', 'province', 'seller.profile', 'cooperative', 'tradingHub'])->loadCount('poolMembers');

        return (new HarvestListingResource($parent))->response()->setStatusCode(201);
    }

    /** POST /api/v1/coop-pools/{harvestListing}/dissolve */
    public function dissolve(Request $request, HarvestListing $harvestListing): JsonResponse
    {
        Gate::authorize('dissolvePool', $harvestListing);

        $this->pooling->dissolve($harvestListing, $request->user());

        return response()->json(['message' => 'Pool dissolved. Unsold volume returned to member lots.']);
    }
}
