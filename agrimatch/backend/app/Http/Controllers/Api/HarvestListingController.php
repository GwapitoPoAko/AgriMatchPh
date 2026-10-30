<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FilterHarvestListingRequest;
use App\Http\Requests\StoreHarvestListingRequest;
use App\Http\Requests\UpdateHarvestListingRequest;
use App\Http\Resources\HarvestListingResource;
use App\Models\B2bOffer;
use App\Models\HarvestListing;
use App\Models\Province;
use App\Models\QualityInspection;
use App\Models\RegionalPriceIndex;
use App\Models\TradingHub;
use App\Services\AuditLogger;
use App\Services\LogisticsPoolingService;
use App\Services\MatchingEngineService;
use App\Services\NotificationService;
use App\Services\PriceIndexService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Marketplace queries, filtering and the listing lifecycle.
 *
 * Security model:
 *  - reads are public; writes require Sanctum auth + HarvestListingPolicy,
 *  - input arrives only through FormRequests (validated() allow-list),
 *  - ownership / verification / pooling fields are set server-side via forceFill(),
 *  - every create / update / delete is written to the hash-chained audit trail.
 */
class HarvestListingController extends Controller
{
    /** Relations needed by HarvestListingResource — always eager loaded to avoid N+1. */
    private const LIST_RELATIONS = [
        'variety:id,crop_category_id,name,local_name,shelf_life_days', 'variety.category:id,name',
        'province:id,name,region', 'seller:id,name,role,is_verified', 'seller.profile:id,user_id,business_name,rating,total_transactions',
        'cooperative:id,name', 'tradingHub:id,short_name,latitude,longitude',
    ];

    public function __construct(
        private readonly MatchingEngineService $matcher,
        private readonly PriceIndexService $priceIndex,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly LogisticsPoolingService $logistics,
    ) {
    }

    /**
     * GET /api/v1/harvest-listings
     *
     * Filters: q, province_id[], crop_variety_id[], crop_category_id[], status[], quality_grade[],
     * harvest_from, harvest_to (window overlap), min_volume, max_volume, min_price, max_price,
     * seller_id, verified_only, organic_only, certified_only, certified_grade, coop_pools_only,
     * cooperative_id, trading_hub_id, include_pool_members, sort, per_page.
     */
    public function index(FilterHarvestListingRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $query = $this->filteredQuery($filters)
            ->with(self::LIST_RELATIONS)
            ->withCount(['pendingOffers', 'poolMembers']);

        $this->applySort($query, $filters['sort'] ?? 'newest');

        $paginator = $query->paginate($filters['per_page'] ?? 12)->withQueryString();
        $this->attachMarketSignals($paginator->getCollection());

        // Facet counts use the same filters minus `status`, so the UI can show
        // "Active (24) · Upcoming (9)" tabs that stay consistent with the grid.
        $statusFacets = $this->filteredQuery(Arr::except($filters, ['status']), applyDefaultStatus: false)
            ->select('harvest_listings.status', DB::raw('COUNT(*) AS total'))
            ->groupBy('harvest_listings.status')
            ->pluck('total', 'status');

        $aggregates = $this->filteredQuery($filters)
            ->selectRaw('COALESCE(SUM(available_volume_kg), 0) AS total_volume_kg')
            ->selectRaw('COALESCE(AVG(unit_price), 0) AS avg_unit_price')
            ->selectRaw('COALESCE(MIN(unit_price), 0) AS min_unit_price')
            ->selectRaw('COALESCE(MAX(unit_price), 0) AS max_unit_price')
            ->selectRaw('SUM(CASE WHEN is_pool_parent = 1 THEN 1 ELSE 0 END) AS coop_pools')
            ->selectRaw('SUM(CASE WHEN certified_grade IN (\'export\', \'grade_a\', \'class_b\', \'class_c\') THEN 1 ELSE 0 END) AS certified')
            ->first();

        return HarvestListingResource::collection($paginator)->additional([
            'meta' => [
                'facets' => ['status' => $statusFacets],
                'aggregates' => [
                    'total_volume_kg' => round((float) $aggregates->total_volume_kg, 2),
                    'avg_unit_price' => round((float) $aggregates->avg_unit_price, 2),
                    'min_unit_price' => round((float) $aggregates->min_unit_price, 2),
                    'max_unit_price' => round((float) $aggregates->max_unit_price, 2),
                    'coop_pools' => (int) $aggregates->coop_pools,
                    'certified' => (int) $aggregates->certified,
                ],
                'price_index_as_of' => $this->priceIndex->latestDate(),
                'applied_filters' => Arr::except($filters, ['page', 'per_page']),
            ],
        ]);
    }

    /**
     * GET /api/v1/harvest-listings/{harvestListing}
     * Adds the regional + hub market comparison, latest inspection, coop pool
     * composition (privacy-reduced) and the freight hint for the pickup point.
     */
    public function show(Request $request, HarvestListing $harvestListing): JsonResponse
    {
        Gate::forUser($request->user('sanctum'))->authorize('view', $harvestListing);

        $harvestListing->load([...self::LIST_RELATIONS, 'latestInspection'])->loadCount(['pendingOffers', 'poolMembers']);
        $this->attachMarketSignals(collect([$harvestListing]));

        // Cheap atomic counter; avoids touching updated_at.
        HarvestListing::whereKey($harvestListing->id)->increment('views_count', 1, ['updated_at' => DB::raw('updated_at')]);

        $latestIndex = RegionalPriceIndex::query()
            ->where('crop_variety_id', $harvestListing->crop_variety_id)
            ->where('province_id', $harvestListing->province_id)
            ->orderByDesc('price_date')
            ->first(['price_date', 'farmgate_price', 'wholesale_price', 'retail_price']);

        $market = $latestIndex ? [
            'as_of' => $latestIndex->price_date->toDateString(),
            'farmgate_price' => $latestIndex->farmgate_price,
            'wholesale_price' => $latestIndex->wholesale_price,
            'retail_price' => $latestIndex->retail_price,
            'vs_farmgate_pct' => $latestIndex->farmgate_price > 0
                ? round(($harvestListing->unit_price / $latestIndex->farmgate_price - 1) * 100, 1)
                : null,
        ] : null;

        $pool = null;
        if ($harvestListing->is_pool_parent) {
            $members = $harvestListing->poolMembers()->get(['id', 'barangay', 'quality_grade', 'certified_grade', 'pooled_volume_kg']);
            $pool = [
                'member_lots' => $members->count(),
                'barangays' => $members->pluck('barangay')->unique()->values(),
                // Individual farmer identities are not exposed to buyers.
                'composition' => $members->map(fn ($m) => [
                    'barangay' => $m->barangay,
                    'grade' => $m->quality_grade,
                    'certified_grade' => $m->certified_grade,
                    'committed_kg' => $m->pooled_volume_kg,
                    'share_pct' => $harvestListing->stock_volume_kg > 0 ? round($m->pooled_volume_kg / $harvestListing->stock_volume_kg * 100, 1) : 0,
                ])->values(),
            ];
        }

        return (new HarvestListingResource($harvestListing))
            ->additional(['meta' => ['market_comparison' => $market, 'coop_pool' => $pool]])
            ->response();
    }

    /**
     * POST /api/v1/harvest-listings
     */
    public function store(StoreHarvestListingRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        if (! empty($data['image'])) {
            $data['image_url'] = Storage::disk('public')->url(Storage::disk('public')->putFile('harvests', $data['image']));
        }

        $listing = DB::transaction(function () use ($data, $user) {
            [$lat, $lng] = $this->pickupPoint($data);

            $listing = new HarvestListing(Arr::only($data, [
                'crop_variety_id', 'province_id', 'municipality', 'barangay', 'title', 'description', 'quality_grade',
                'stock_volume_kg', 'unit_price', 'harvest_date_start', 'harvest_date_end', 'image_url',
            ]) + [
                'available_volume_kg' => $data['stock_volume_kg'],
                'minimum_order_kg' => $data['minimum_order_kg'] ?? 0,
                'is_organic' => $data['is_organic'] ?? false,
                'price_negotiable' => $data['price_negotiable'] ?? true,
                'status' => HarvestListing::statusForHarvestStart($data['harvest_date_start']),
                'pickup_latitude' => $lat,
                'pickup_longitude' => $lng,
                'latitude' => $lat,
                'longitude' => $lng,
            ]);

            $listing->forceFill([
                'reference_code' => HarvestListing::nextReferenceCode(),
                'seller_id' => $user->id,
                'cooperative_id' => $user->cooperativeId(),
                // Seller KYC carries over; product-level certification only via inspection.
                'is_verified' => (bool) $user->is_verified,
                'trading_hub_id' => $lat !== null ? TradingHub::nearestTo($lat, $lng)?->id : null,
                'inspection_status' => ! empty($data['request_inspection']) ? 'pending' : 'not_requested',
            ])->save();

            $inspection = null;
            if (! empty($data['request_inspection'])) {
                $inspection = new QualityInspection([
                    'harvest_listing_id' => $listing->id,
                    'scheduled_at' => Carbon::parse($data['preferred_inspection_date'])->setTime(9, 0),
                    'remarks' => isset($data['claimed_grade']) ? "Seller claims {$data['claimed_grade']}." : null,
                ]);
                $inspection->forceFill([
                    'reference_code' => QualityInspection::nextReferenceCode(),
                    'requested_by_id' => $user->id,
                    'cooperative_id' => $listing->cooperative_id,
                    'status' => QualityInspection::STATUS_REQUESTED,
                ])->save();
            }

            $this->audit->record('listing.created', $listing, [
                'reference_code' => $listing->reference_code,
                'crop_variety_id' => $listing->crop_variety_id,
                'stock_volume_kg' => $listing->stock_volume_kg,
                'unit_price' => $listing->unit_price,
                'harvest_window' => [$listing->harvest_date_start->toDateString(), $listing->harvest_date_end->toDateString()],
                'inspection_requested' => (bool) $inspection,
            ], actor: $user);

            if ($inspection) {
                $this->notifications->inspectionRequested($inspection->setRelation('listing', $listing->load('province')));
            }

            return $listing;
        });

        $listing->load([...self::LIST_RELATIONS, 'latestInspection']);
        $this->attachMarketSignals(collect([$listing]));

        return (new HarvestListingResource($listing))->response()->setStatusCode(201);
    }

    /**
     * PUT/PATCH /api/v1/harvest-listings/{harvestListing}
     */
    public function update(UpdateHarvestListingRequest $request, HarvestListing $harvestListing): HarvestListingResource
    {
        $data = $request->validated();
        if (! empty($data['image'])) {
            $data['image_url'] = Storage::disk('public')->url(Storage::disk('public')->putFile('harvests', $data['image']));
            unset($data['image']);
        }

        DB::transaction(function () use ($data, $harvestListing, $request) {
            $harvestListing = HarvestListing::whereKey($harvestListing->id)->lockForUpdate()->firstOrFail();

            // When stock grows/shrinks without an explicit available volume, shift
            // availability by the same delta so already-contracted volume is preserved.
            if (array_key_exists('stock_volume_kg', $data) && ! array_key_exists('available_volume_kg', $data)) {
                $delta = $data['stock_volume_kg'] - $harvestListing->stock_volume_kg;
                $data['available_volume_kg'] = max(0, min($data['stock_volume_kg'], $harvestListing->available_volume_kg + $delta));
            }

            if (array_key_exists('harvest_date_start', $data) && ! array_key_exists('status', $data)
                && in_array($harvestListing->status, HarvestListing::TRADABLE_STATUSES, true)) {
                $data['status'] = HarvestListing::statusForHarvestStart($data['harvest_date_start']);
            }

            if (($data['available_volume_kg'] ?? $harvestListing->available_volume_kg) <= 0
                && in_array($data['status'] ?? $harvestListing->status, HarvestListing::TRADABLE_STATUSES, true)) {
                $data['status'] = HarvestListing::STATUS_SOLD_OUT;
            }

            $harvestListing->fill($data);
            if ($harvestListing->isDirty(['pickup_latitude', 'pickup_longitude']) && $harvestListing->pickup_latitude !== null) {
                $harvestListing->forceFill([
                    'latitude' => $harvestListing->pickup_latitude,
                    'longitude' => $harvestListing->pickup_longitude,
                    'trading_hub_id' => TradingHub::nearestTo($harvestListing->pickup_latitude, $harvestListing->pickup_longitude)?->id,
                ]);
            }
            // A seller changing grade invalidates the old certificate.
            if ($harvestListing->isDirty('quality_grade') && $harvestListing->certified_grade) {
                $harvestListing->forceFill(['certified_grade' => null, 'inspection_status' => 'not_requested']);
            }
            $harvestListing->save();

            [$new, $old] = AuditLogger::diff($harvestListing);
            if ($new) {
                $this->audit->record('listing.updated', $harvestListing, $new, $old, $request->user());
            }
        });

        $fresh = $harvestListing->fresh([...self::LIST_RELATIONS, 'latestInspection']);
        $this->attachMarketSignals(collect([$fresh]));

        return new HarvestListingResource($fresh);
    }

    /**
     * DELETE /api/v1/harvest-listings/{harvestListing}
     * Soft-deletes and auto-expires any still-pending offers.
     */
    public function destroy(Request $request, HarvestListing $harvestListing): JsonResponse
    {
        Gate::authorize('delete', $harvestListing);

        DB::transaction(function () use ($harvestListing, $request) {
            $expired = $harvestListing->offers()
                ->where('status', B2bOffer::STATUS_PENDING)
                ->update(['status' => B2bOffer::STATUS_EXPIRED, 'responded_at' => now()]);
            $harvestListing->delete();

            $this->audit->record('listing.deleted', $harvestListing, ['expired_offers' => $expired],
                ['status' => $harvestListing->status, 'available_volume_kg' => $harvestListing->available_volume_kg], $request->user());
        });

        return response()->json(['message' => 'Listing removed.']);
    }

    /**
     * GET /api/v1/harvest-listings/{harvestListing}/matches
     * Buyer demands that fit this lot, ranked by the matching engine (seller view).
     */
    public function matches(Request $request, HarvestListing $harvestListing): JsonResponse
    {
        Gate::authorize('viewMatches', $harvestListing);

        $limit = (int) min(50, max(1, $request->integer('limit', 10)));

        return response()->json(['data' => $this->matcher->matchListing($harvestListing, $limit)]);
    }

    /**
     * GET /api/v1/harvest-listings/{harvestListing}/freight-estimate?departure_date=
     * Quick Hakot quote from this lot's pickup point to its nearest hub.
     */
    public function freightEstimate(Request $request, HarvestListing $harvestListing): JsonResponse
    {
        $validated = $request->validate([
            'departure_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'volume_kg' => ['nullable', 'numeric', 'min:50', 'max:200000'],
        ]);

        abort_if($harvestListing->pickup_latitude === null || ! $harvestListing->trading_hub_id, 422, 'This listing has no pickup point yet.');

        $hub = TradingHub::with('province:id,region_code')->findOrFail($harvestListing->trading_hub_id);
        $volume = (float) ($validated['volume_kg'] ?? $harvestListing->available_volume_kg);
        abort_if($volume <= 0, 422, 'Nothing left to ship on this listing.');

        $result = $this->logistics->quoteForShipper($hub, [
            'volume_kg' => $volume,
            'lat' => $harvestListing->pickup_latitude,
            'lng' => $harvestListing->pickup_longitude,
            'barangay' => $harvestListing->barangay,
            'listing_id' => $harvestListing->id,
        ], $validated['departure_date'] ?? Carbon::today()->addDay(), $request->user('sanctum'));

        return response()->json(['data' => $result]);
    }

    /* ------------------------------------------------------------------ */

    private function attachMarketSignals(Collection $listings): void
    {
        $signals = $this->priceIndex->signalsForListings($listings);
        foreach ($listings as $listing) {
            if (isset($signals[$listing->id])) {
                $listing->setAttribute('market_signal', $signals[$listing->id]);
            }
        }
    }

    /** @return array{0: ?float, 1: ?float} */
    private function pickupPoint(array $data): array
    {
        if (isset($data['pickup_latitude'], $data['pickup_longitude'])) {
            return [(float) $data['pickup_latitude'], (float) $data['pickup_longitude']];
        }
        $province = Province::find($data['province_id'], ['latitude', 'longitude']);

        return $province ? [$province->latitude, $province->longitude] : [null, null];
    }

    /**
     * Builds the filtered base query. Kept separate so index(), facets and
     * aggregates all apply identical filter semantics. Every value is bound
     * through the query builder — no raw interpolation of user input.
     */
    private function filteredQuery(array $f, bool $applyDefaultStatus = true): Builder
    {
        $query = HarvestListing::query();

        if (! empty($f['q'])) {
            $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%';
            $query->where(function (Builder $q) use ($term) {
                $q->where('harvest_listings.title', 'like', $term)
                    ->orWhere('harvest_listings.reference_code', 'like', $term)
                    ->orWhere('harvest_listings.municipality', 'like', $term)
                    ->orWhere('harvest_listings.barangay', 'like', $term)
                    ->orWhereHas('variety', fn (Builder $v) => $v->where('name', 'like', $term)->orWhere('local_name', 'like', $term))
                    ->orWhereHas('cooperative', fn (Builder $c) => $c->where('name', 'like', $term));
            });
        }

        if (! empty($f['status'])) {
            $query->whereIn('harvest_listings.status', $f['status']);
        } elseif ($applyDefaultStatus) {
            $query->whereIn('harvest_listings.status', HarvestListing::TRADABLE_STATUSES);
        }

        // Member lots that are partly pooled stay visible; fully pooled ones carry status "pooled".
        if (empty($f['include_pool_members']) && ! empty($f['coop_pools_only'])) {
            $query->where('harvest_listings.is_pool_parent', true);
        }

        $query
            ->when(! empty($f['province_id']), fn (Builder $q) => $q->whereIn('harvest_listings.province_id', $f['province_id']))
            ->when(! empty($f['crop_variety_id']), fn (Builder $q) => $q->whereIn('harvest_listings.crop_variety_id', $f['crop_variety_id']))
            ->when(! empty($f['crop_category_id']), fn (Builder $q) => $q->whereIn(
                'harvest_listings.crop_variety_id',
                fn ($sub) => $sub->select('id')->from('crop_varieties')->whereIn('crop_category_id', $f['crop_category_id'])
            ))
            ->when(! empty($f['quality_grade']), fn (Builder $q) => $q->whereIn('harvest_listings.quality_grade', $f['quality_grade']))
            ->harvestOverlaps($f['harvest_from'] ?? null, $f['harvest_to'] ?? null)
            ->when(isset($f['min_volume']), fn (Builder $q) => $q->where('harvest_listings.available_volume_kg', '>=', $f['min_volume']))
            ->when(isset($f['max_volume']), fn (Builder $q) => $q->where('harvest_listings.available_volume_kg', '<=', $f['max_volume']))
            ->when(isset($f['min_price']), fn (Builder $q) => $q->where('harvest_listings.unit_price', '>=', $f['min_price']))
            ->when(isset($f['max_price']), fn (Builder $q) => $q->where('harvest_listings.unit_price', '<=', $f['max_price']))
            ->when(! empty($f['seller_id']), fn (Builder $q) => $q->where('harvest_listings.seller_id', $f['seller_id']))
            ->when(! empty($f['cooperative_id']), fn (Builder $q) => $q->where('harvest_listings.cooperative_id', $f['cooperative_id']))
            ->when(! empty($f['trading_hub_id']), fn (Builder $q) => $q->where('harvest_listings.trading_hub_id', $f['trading_hub_id']))
            ->when(! empty($f['verified_only']), fn (Builder $q) => $q->where('harvest_listings.is_verified', true))
            ->when(! empty($f['organic_only']), fn (Builder $q) => $q->where('harvest_listings.is_organic', true))
            ->when(! empty($f['certified_only']), fn (Builder $q) => $q->whereIn('harvest_listings.certified_grade', ['export', 'grade_a', 'class_b', 'class_c']))
            ->when(! empty($f['certified_grade']), fn (Builder $q) => $q->where('harvest_listings.certified_grade', $f['certified_grade']));

        return $query;
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('harvest_listings.unit_price'),
            'price_desc' => $query->orderByDesc('harvest_listings.unit_price'),
            'volume_desc' => $query->orderByDesc('harvest_listings.available_volume_kg'),
            'harvest_soonest' => $query->orderBy('harvest_listings.harvest_date_start'),
            'popular' => $query->orderByDesc('harvest_listings.views_count'),
            default => $query->orderByDesc('harvest_listings.created_at'),
        };

        $query->orderByDesc('harvest_listings.id');
    }
}
