<?php

namespace App\Services;

use App\Models\BuyerDemand;
use App\Models\CropVariety;
use App\Models\HarvestListing;
use App\Models\Province;
use App\Models\RegionalPriceIndex;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pairs buyer demand with active / upcoming harvest listings (and the reverse).
 *
 * Every candidate gets a 0–100 score built from six weighted signals:
 *
 *   crop       30  exact variety = 1.0, same-category substitute = 0.4
 *   proximity  25  great-circle distance between provinces vs. the buyer's max radius
 *   timing     15  overlap of harvest window and the buyer's need window (shelf life aware)
 *   volume     15  how much of the requested quantity this lot can cover (respecting MOQ)
 *   price      10  estimated landed cost vs. buyer's ceiling, or vs. the regional price index
 *   trust       5  verification status and seller rating
 *
 * Hard filters (variety/category, tradable status, grade, harvest window, 1.25× radius)
 * run in SQL so only plausible lots are scored in PHP. The result also contains a greedy
 * fill plan that splits the order across the best-scoring lots.
 */
class MatchingEngineService
{
    public const WEIGHTS = [
        'crop' => 30,
        'proximity' => 25,
        'timing' => 15,
        'volume' => 15,
        'price' => 10,
        'trust' => 5,
    ];

    /** Trucking cost in PHP per kg per road-km. */
    public const FREIGHT_PER_KG_KM = 0.012;

    /** Great-circle → road distance correction. */
    public const ROAD_FACTOR = 1.3;

    /** Minimum haul assumed even inside one province (farm → town). */
    public const MIN_HAUL_KM = 25;

    /** RoRo / inter-island surcharge in PHP per kg. */
    public const INTER_ISLAND_SURCHARGE = 2.50;

    public const SUBSTITUTE_CROP_FACTOR = 0.4;
    public const DEFAULT_MAX_DISTANCE_KM = 300;
    public const MIN_SCORE = 35.0;
    public const CANDIDATE_POOL = 120;

    /* ================================================================== */
    /* Public API                                                          */
    /* ================================================================== */

    /**
     * Rank harvest listings for a stored buyer demand.
     */
    public function matchDemand(BuyerDemand $demand, int $limit = 10, bool $includeSubstitutes = false): array
    {
        return $this->match([
            'crop_variety_id' => $demand->crop_variety_id,
            'delivery_province_id' => $demand->delivery_province_id,
            'quantity_kg' => $demand->quantity_kg,
            'needed_from' => $demand->needed_from->toDateString(),
            'needed_by' => $demand->needed_by->toDateString(),
            'max_unit_price' => $demand->max_unit_price,
            'max_distance_km' => $demand->max_distance_km,
            'min_quality_grade' => $demand->min_quality_grade,
            'include_substitutes' => $includeSubstitutes,
        ], $demand->buyer_id, $limit) + ['demand_id' => $demand->id, 'demand_reference' => $demand->reference_code];
    }

    /**
     * Rank harvest listings for ad-hoc criteria.
     *
     * @param  array{
     *   crop_variety_id:int, delivery_province_id:int, quantity_kg:float|int,
     *   needed_from?:string|null, needed_by?:string|null, max_unit_price?:float|null,
     *   max_distance_km?:int|null, min_quality_grade?:string|null, include_substitutes?:bool
     * }  $criteria
     */
    public function match(array $criteria, ?int $excludeSellerId = null, int $limit = 10): array
    {
        $criteria = $this->normalizeCriteria($criteria);

        /** @var CropVariety $variety */
        $variety = CropVariety::with('category')->findOrFail($criteria['crop_variety_id']);
        /** @var Province $destination */
        $destination = Province::findOrFail($criteria['delivery_province_id']);

        $candidates = $this->candidateListingsQuery($variety, $destination, $criteria, $excludeSellerId)->get();

        $benchmarks = $this->benchmarkPrices(
            $candidates->pluck('crop_variety_id')->unique()->all(),
            $candidates->pluck('province_id')->unique()->all()
        );

        $matches = $candidates
            ->map(fn (HarvestListing $listing) => $this->scorePair(
                listing: $listing,
                criteria: $criteria,
                requestedVariety: $variety,
                origin: $listing->province,
                destination: $destination,
                distanceKm: (float) $listing->distance_km,
                benchmark: $benchmarks[$listing->crop_variety_id . ':' . $listing->province_id] ?? null,
            ))
            ->filter(fn (array $m) => $m['score'] >= self::MIN_SCORE)
            ->sortByDesc('score')
            ->values();

        $allocation = $this->allocate($matches, $criteria['quantity_kg']);

        return [
            'criteria' => array_merge($criteria, [
                'crop_variety' => $variety->name,
                'crop_category' => $variety->category?->name,
                'delivery_province' => $destination->name,
            ]),
            'summary' => [
                'candidates_scanned' => $candidates->count(),
                'matches_found' => $matches->count(),
                'best_score' => $matches->first()['score'] ?? null,
                'total_available_kg' => round($matches->sum('available_volume_kg'), 2),
                'coverage_pct' => $allocation['coverage_pct'],
            ],
            'matches' => $matches->take($limit)->map(fn (array $m) => $this->presentListingMatch($m))->values()->all(),
            'allocation' => $allocation,
        ];
    }

    /**
     * Reverse match: rank open buyer demands for one harvest listing (seller view).
     */
    public function matchListing(HarvestListing $listing, int $limit = 10): array
    {
        $listing->loadMissing(['variety.category', 'province', 'seller.profile']);
        $origin = $listing->province;

        $distanceSql = $this->haversineSql('dp.latitude', 'dp.longitude');

        $demands = BuyerDemand::query()
            ->select('buyer_demands.*')
            ->selectRaw("{$distanceSql} AS distance_km", [$origin->latitude, $origin->longitude, $origin->latitude])
            ->join('provinces as dp', 'dp.id', '=', 'buyer_demands.delivery_province_id')
            ->whereIn('buyer_demands.status', BuyerDemand::OPEN_STATUSES)
            ->where('buyer_demands.crop_variety_id', $listing->crop_variety_id)
            ->where('buyer_demands.buyer_id', '!=', $listing->seller_id)
            ->whereDate('buyer_demands.needed_by', '>=', $listing->harvest_date_start->toDateString())
            ->whereRaw("{$distanceSql} <= buyer_demands.max_distance_km * 1.25", [$origin->latitude, $origin->longitude, $origin->latitude])
            ->with(['buyer.profile', 'deliveryProvince', 'variety'])
            ->orderBy('distance_km')
            ->limit(self::CANDIDATE_POOL)
            ->get();

        $benchmark = $this->benchmarkPrices([$listing->crop_variety_id], [$listing->province_id])
            [$listing->crop_variety_id . ':' . $listing->province_id] ?? null;

        $matches = $demands
            ->filter(function (BuyerDemand $demand) use ($listing) {
                $required = HarvestListing::GRADE_RANK[$demand->min_quality_grade] ?? 3;

                return (HarvestListing::GRADE_RANK[$listing->quality_grade] ?? 3) <= $required;
            })
            ->map(function (BuyerDemand $demand) use ($listing, $origin, $benchmark) {
                $criteria = $this->normalizeCriteria([
                    'crop_variety_id' => $demand->crop_variety_id,
                    'delivery_province_id' => $demand->delivery_province_id,
                    'quantity_kg' => $demand->quantity_kg,
                    'needed_from' => $demand->needed_from->toDateString(),
                    'needed_by' => $demand->needed_by->toDateString(),
                    'max_unit_price' => $demand->max_unit_price,
                    'max_distance_km' => $demand->max_distance_km,
                    'min_quality_grade' => $demand->min_quality_grade,
                ]);

                $scored = $this->scorePair(
                    listing: $listing,
                    criteria: $criteria,
                    requestedVariety: $demand->variety,
                    origin: $origin,
                    destination: $demand->deliveryProvince,
                    distanceKm: (float) $demand->distance_km,
                    benchmark: $benchmark,
                );

                return $scored + ['demand' => $demand];
            })
            ->filter(fn (array $m) => $m['score'] >= self::MIN_SCORE)
            ->sortByDesc('score')
            ->values();

        return [
            'listing' => [
                'id' => $listing->id,
                'reference_code' => $listing->reference_code,
                'title' => $listing->title,
                'crop_variety' => $listing->variety->name,
                'province' => $origin->name,
                'available_volume_kg' => $listing->available_volume_kg,
                'unit_price' => $listing->unit_price,
            ],
            'summary' => [
                'candidates_scanned' => $demands->count(),
                'matches_found' => $matches->count(),
                'best_score' => $matches->first()['score'] ?? null,
                'total_demand_kg' => round($matches->sum(fn (array $m) => $m['demand']->quantity_kg), 2),
            ],
            'matches' => $matches->take($limit)->map(fn (array $m) => $this->presentDemandMatch($m))->values()->all(),
        ];
    }

    /* ================================================================== */
    /* Candidate retrieval                                                 */
    /* ================================================================== */

    protected function candidateListingsQuery(
        CropVariety $variety,
        Province $destination,
        array $criteria,
        ?int $excludeSellerId
    ): Builder {
        $distanceSql = $this->haversineSql('p.latitude', 'p.longitude');
        $bindings = [$destination->latitude, $destination->longitude, $destination->latitude];

        // Stored crops (onion, grain, potato) can be bought before the need window
        // and held, so widen the window backwards by part of the shelf life.
        $storageBufferDays = (int) min(30, floor(($variety->shelf_life_days ?? 0) / 3));
        $windowFrom = Carbon::parse($criteria['needed_from'])->subDays($storageBufferDays)->toDateString();

        return HarvestListing::query()
            ->select('harvest_listings.*')
            ->selectRaw("{$distanceSql} AS distance_km", $bindings)
            ->join('provinces as p', 'p.id', '=', 'harvest_listings.province_id')
            ->join('crop_varieties as cv', 'cv.id', '=', 'harvest_listings.crop_variety_id')
            ->tradable()
            ->when(
                $criteria['include_substitutes'],
                fn (Builder $q) => $q->where('cv.crop_category_id', $variety->crop_category_id),
                fn (Builder $q) => $q->where('harvest_listings.crop_variety_id', $variety->id)
            )
            ->harvestOverlaps($windowFrom, $criteria['needed_by'])
            ->meetsGrade($criteria['min_quality_grade'])
            ->when($excludeSellerId, fn (Builder $q) => $q->where('harvest_listings.seller_id', '!=', $excludeSellerId))
            ->whereRaw("{$distanceSql} <= ?", [...$bindings, $criteria['max_distance_km'] * 1.25])
            ->with(['variety.category', 'province', 'seller.profile'])
            ->orderBy('distance_km')
            ->limit(self::CANDIDATE_POOL);
    }

    /**
     * Latest 7-day average farm-gate price per (variety, province) — used when the
     * buyer gives no price ceiling, so we can still tell a fair price from a high one.
     *
     * @return array<string, float> keyed "varietyId:provinceId"
     */
    protected function benchmarkPrices(array $varietyIds, array $provinceIds): array
    {
        if (empty($varietyIds) || empty($provinceIds)) {
            return [];
        }

        $latestDate = RegionalPriceIndex::max('price_date');
        if (! $latestDate) {
            return [];
        }

        $since = Carbon::parse($latestDate)->subDays(6)->toDateString();

        return RegionalPriceIndex::query()
            ->select('crop_variety_id', 'province_id', DB::raw('AVG(farmgate_price) AS avg_price'))
            ->whereIn('crop_variety_id', $varietyIds)
            ->whereIn('province_id', $provinceIds)
            ->whereBetween('price_date', [$since, $latestDate])
            ->groupBy('crop_variety_id', 'province_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->crop_variety_id . ':' . $row->province_id => (float) $row->avg_price])
            ->all();
    }

    /* ================================================================== */
    /* Scoring                                                             */
    /* ================================================================== */

    protected function scorePair(
        HarvestListing $listing,
        array $criteria,
        CropVariety $requestedVariety,
        Province $origin,
        Province $destination,
        float $distanceKm,
        ?float $benchmark
    ): array {
        $reasons = [];
        $flags = [];

        // --- crop ------------------------------------------------------
        $exactCrop = $listing->crop_variety_id === $requestedVariety->id;
        $crop = $exactCrop ? 1.0 : self::SUBSTITUTE_CROP_FACTOR;
        $reasons[] = $exactCrop
            ? "Exact variety: {$listing->variety->name}"
            : "Substitute within {$listing->variety->category?->name}: {$listing->variety->name}";

        // --- proximity -------------------------------------------------
        $maxKm = max(1, (int) $criteria['max_distance_km']);
        if ($distanceKm <= $maxKm) {
            $proximity = 1 - 0.7 * ($distanceKm / $maxKm);
        } else {
            $proximity = max(0.0, 0.3 * (1 - ($distanceKm - $maxKm) / (0.25 * $maxKm)));
            $flags[] = 'outside_preferred_radius';
        }
        $interIsland = $origin->islandGroup() !== $destination->islandGroup();
        if ($interIsland) {
            $flags[] = 'inter_island';
        }
        $reasons[] = $distanceKm < 1
            ? "Same province ({$origin->name})"
            : sprintf('%s km from %s%s', number_format($distanceKm, 0), $destination->name, $interIsland ? ' (inter-island)' : '');

        // --- timing ----------------------------------------------------
        [$timing, $timingNote] = $this->timingScore($listing, $criteria);
        $reasons[] = $timingNote;

        // --- volume ----------------------------------------------------
        $requested = max(1.0, (float) $criteria['quantity_kg']);
        $available = (float) $listing->available_volume_kg;
        $volume = min(1.0, $available / $requested);
        if ($listing->minimum_order_kg > $requested) {
            $volume *= 0.3;
            $flags[] = 'below_minimum_order';
        }
        $reasons[] = $available >= $requested
            ? sprintf('Covers full %s kg', number_format($requested))
            : sprintf('Covers %d%% (%s of %s kg)', (int) round($available / $requested * 100), number_format($available), number_format($requested));

        // --- price (landed) --------------------------------------------
        $freight = $this->estimateFreightPerKg($distanceKm, $interIsland);
        $landed = round($listing->unit_price + $freight, 2);
        [$price, $priceNote] = $this->priceScore($listing->unit_price, $landed, $criteria['max_unit_price'], $benchmark);
        if ($criteria['max_unit_price'] && $landed > $criteria['max_unit_price']) {
            $flags[] = 'above_price_ceiling';
        }
        $reasons[] = $priceNote;

        // --- trust -----------------------------------------------------
        $sellerVerified = (bool) ($listing->is_verified || $listing->seller?->is_verified);
        $rating = (float) ($listing->seller?->profile?->rating ?? 0);
        $trust = ($sellerVerified ? 0.6 : 0.2) + 0.4 * min(1, $rating / 5);
        if ($sellerVerified) {
            $reasons[] = sprintf('Verified seller · %.1f★', $rating);
        }

        $breakdown = [
            'crop' => $crop,
            'proximity' => $proximity,
            'timing' => $timing,
            'volume' => $volume,
            'price' => $price,
            'trust' => $trust,
        ];

        $score = 0.0;
        $weighted = [];
        foreach (self::WEIGHTS as $key => $weight) {
            $points = round($weight * $breakdown[$key], 1);
            $weighted[$key] = ['score' => round($breakdown[$key], 3), 'points' => $points, 'weight' => $weight];
            $score += $points;
        }
        $score = round(min(100, $score), 1);

        return [
            'listing' => $listing,
            'score' => $score,
            'tier' => $this->tier($score),
            'breakdown' => $weighted,
            'distance_km' => round($distanceKm, 1),
            'available_volume_kg' => $available,
            'freight_per_kg' => $freight,
            'landed_price_per_kg' => $landed,
            'benchmark_price' => $benchmark ? round($benchmark, 2) : null,
            'exact_crop' => $exactCrop,
            'reasons' => $reasons,
            'flags' => $flags,
        ];
    }

    /**
     * @return array{0: float, 1: string}
     */
    protected function timingScore(HarvestListing $listing, array $criteria): array
    {
        $needFrom = Carbon::parse($criteria['needed_from'])->startOfDay();
        $needBy = Carbon::parse($criteria['needed_by'])->startOfDay();
        $harvestStart = $listing->harvest_date_start->copy()->startOfDay();
        $harvestEnd = $listing->harvest_date_end->copy()->startOfDay();

        $overlapStart = $harvestStart->max($needFrom);
        $overlapEnd = $harvestEnd->min($needBy);

        if ($overlapEnd->greaterThanOrEqualTo($overlapStart)) {
            $overlapDays = (int) round($overlapStart->diffInDays($overlapEnd, true)) + 1;
            $needDays = (int) round($needFrom->diffInDays($needBy, true)) + 1;
            $listingDays = (int) round($harvestStart->diffInDays($harvestEnd, true)) + 1;
            $coverage = min(1.0, $overlapDays / max(1, min($needDays, $listingDays)));

            // Stock that is already being harvested and needed soon is the most actionable.
            $readyNow = $listing->status === HarvestListing::STATUS_ACTIVE
                && $needFrom->lessThanOrEqualTo(Carbon::today()->addDays(3));
            $score = min(1.0, 0.55 + 0.45 * $coverage + ($readyNow ? 0.1 : 0));

            $note = $readyNow
                ? 'Harvest in progress — ready to ship'
                : sprintf('Harvest window overlaps need by %d day%s', $overlapDays, $overlapDays === 1 ? '' : 's');

            return [$score, $note];
        }

        // Harvest finished before the need window but crop stores well enough to bridge it.
        $gapDays = (int) round($harvestEnd->diffInDays($needFrom, true));
        $shelfLife = (int) ($listing->variety->shelf_life_days ?? 0);
        if ($harvestEnd->lessThan($needFrom) && $shelfLife > 0 && $gapDays <= $shelfLife) {
            return [max(0.2, 0.5 * (1 - $gapDays / $shelfLife)), "Stored stock — harvested {$gapDays} days before need"];
        }

        return [0.0, 'Harvest window outside need window'];
    }

    /**
     * @return array{0: float, 1: string}
     */
    protected function priceScore(float $unitPrice, float $landed, ?float $ceiling, ?float $benchmark): array
    {
        if ($ceiling && $ceiling > 0) {
            $ratio = $landed / $ceiling;
            if ($ratio <= 1) {
                $score = 0.6 + 0.4 * min(1, (1 - $ratio) / 0.15);

                return [$score, sprintf('Landed ₱%.2f/kg within ₱%.2f ceiling', $landed, $ceiling)];
            }

            return [max(0.0, 0.6 - ($ratio - 1) * 4), sprintf('Landed ₱%.2f/kg is %d%% over ceiling', $landed, (int) round(($ratio - 1) * 100))];
        }

        if ($benchmark && $benchmark > 0) {
            $ratio = $unitPrice / $benchmark;
            $score = max(0.0, min(1.0, 1 - ($ratio - 0.85) / 0.35));
            $delta = ($ratio - 1) * 100;

            return [$score, sprintf('%s%.1f%% vs. 7-day farm-gate index', $delta >= 0 ? '+' : '', $delta)];
        }

        return [0.6, 'No price benchmark available'];
    }

    protected function estimateFreightPerKg(float $distanceKm, bool $interIsland): float
    {
        $roadKm = max(self::MIN_HAUL_KM, $distanceKm * self::ROAD_FACTOR);

        return round($roadKm * self::FREIGHT_PER_KG_KM + ($interIsland ? self::INTER_ISLAND_SURCHARGE : 0), 2);
    }

    protected function tier(float $score): string
    {
        return match (true) {
            $score >= 80 => 'excellent',
            $score >= 65 => 'strong',
            $score >= 50 => 'fair',
            default => 'weak',
        };
    }

    /* ================================================================== */
    /* Allocation                                                          */
    /* ================================================================== */

    /**
     * Greedy fill: take volume from the best-scoring lots until the order is covered,
     * skipping lots whose minimum order exceeds the remaining need.
     */
    protected function allocate(Collection $matches, float $quantityKg): array
    {
        $remaining = $quantityKg;
        $lines = [];
        $totalCost = 0.0;

        foreach ($matches as $match) {
            if ($remaining <= 0) {
                break;
            }

            /** @var HarvestListing $listing */
            $listing = $match['listing'];
            $take = min($remaining, $match['available_volume_kg']);

            if ($take < $listing->minimum_order_kg) {
                continue;
            }

            $lineCost = $take * $match['landed_price_per_kg'];
            $totalCost += $lineCost;
            $remaining -= $take;

            $lines[] = [
                'harvest_listing_id' => $listing->id,
                'reference_code' => $listing->reference_code,
                'seller' => $listing->seller?->profile?->business_name ?? $listing->seller?->name,
                'province' => $listing->province?->name,
                'volume_kg' => round($take, 2),
                'unit_price' => $listing->unit_price,
                'landed_price_per_kg' => $match['landed_price_per_kg'],
                'estimated_cost' => round($lineCost, 2),
                'score' => $match['score'],
            ];
        }

        $allocated = $quantityKg - max(0, $remaining);

        return [
            'requested_kg' => round($quantityKg, 2),
            'allocated_kg' => round($allocated, 2),
            'shortfall_kg' => round(max(0, $remaining), 2),
            'coverage_pct' => $quantityKg > 0 ? round($allocated / $quantityKg * 100, 1) : 0.0,
            'estimated_total_cost' => round($totalCost, 2),
            'weighted_landed_price' => $allocated > 0 ? round($totalCost / $allocated, 2) : null,
            'lines' => $lines,
        ];
    }

    /* ================================================================== */
    /* Presentation & helpers                                              */
    /* ================================================================== */

    protected function presentListingMatch(array $m): array
    {
        /** @var HarvestListing $l */
        $l = $m['listing'];

        return [
            'score' => $m['score'],
            'tier' => $m['tier'],
            'breakdown' => $m['breakdown'],
            'reasons' => $m['reasons'],
            'flags' => $m['flags'],
            'distance_km' => $m['distance_km'],
            'freight_per_kg' => $m['freight_per_kg'],
            'landed_price_per_kg' => $m['landed_price_per_kg'],
            'benchmark_price' => $m['benchmark_price'],
            'exact_crop' => $m['exact_crop'],
            'listing' => [
                'id' => $l->id,
                'reference_code' => $l->reference_code,
                'title' => $l->title,
                'crop_variety' => $l->variety?->name,
                'crop_category' => $l->variety?->category?->name,
                'province' => $l->province?->name,
                'municipality' => $l->municipality,
                'barangay' => $l->barangay,
                'quality_grade' => $l->quality_grade,
                'is_organic' => $l->is_organic,
                'available_volume_kg' => $l->available_volume_kg,
                'minimum_order_kg' => $l->minimum_order_kg,
                'unit_price' => $l->unit_price,
                'harvest_date_start' => $l->harvest_date_start?->toDateString(),
                'harvest_date_end' => $l->harvest_date_end?->toDateString(),
                'status' => $l->status,
                'is_verified' => $l->is_verified,
                'seller' => [
                    'id' => $l->seller?->id,
                    'name' => $l->seller?->name,
                    'business_name' => $l->seller?->profile?->business_name,
                    'role' => $l->seller?->role,
                    'rating' => $l->seller?->profile?->rating,
                    'is_verified' => $l->seller?->is_verified,
                ],
            ],
        ];
    }

    protected function presentDemandMatch(array $m): array
    {
        /** @var BuyerDemand $d */
        $d = $m['demand'];

        return [
            'score' => $m['score'],
            'tier' => $m['tier'],
            'breakdown' => $m['breakdown'],
            'reasons' => $m['reasons'],
            'flags' => $m['flags'],
            'distance_km' => $m['distance_km'],
            'landed_price_per_kg' => $m['landed_price_per_kg'],
            'demand' => [
                'id' => $d->id,
                'reference_code' => $d->reference_code,
                'quantity_kg' => $d->quantity_kg,
                'max_unit_price' => $d->max_unit_price,
                'needed_from' => $d->needed_from?->toDateString(),
                'needed_by' => $d->needed_by?->toDateString(),
                'delivery_province' => $d->deliveryProvince?->name,
                'notes' => $d->notes,
                'buyer' => [
                    'id' => $d->buyer?->id,
                    'name' => $d->buyer?->name,
                    'business_name' => $d->buyer?->profile?->business_name,
                    'is_verified' => $d->buyer?->is_verified,
                ],
            ],
        ];
    }

    protected function normalizeCriteria(array $criteria): array
    {
        $from = isset($criteria['needed_from']) && $criteria['needed_from']
            ? Carbon::parse($criteria['needed_from'])
            : Carbon::today();
        $by = isset($criteria['needed_by']) && $criteria['needed_by']
            ? Carbon::parse($criteria['needed_by'])
            : $from->copy()->addDays(30);

        if ($by->lessThan($from)) {
            [$from, $by] = [$by, $from];
        }

        $grade = strtoupper((string) ($criteria['min_quality_grade'] ?? 'C'));

        return [
            'crop_variety_id' => (int) $criteria['crop_variety_id'],
            'delivery_province_id' => (int) $criteria['delivery_province_id'],
            'quantity_kg' => (float) $criteria['quantity_kg'],
            'needed_from' => $from->toDateString(),
            'needed_by' => $by->toDateString(),
            'max_unit_price' => isset($criteria['max_unit_price']) && $criteria['max_unit_price'] !== null
                ? (float) $criteria['max_unit_price']
                : null,
            'max_distance_km' => (int) ($criteria['max_distance_km'] ?? self::DEFAULT_MAX_DISTANCE_KM),
            'min_quality_grade' => array_key_exists($grade, HarvestListing::GRADE_RANK) ? $grade : 'C',
            'include_substitutes' => (bool) ($criteria['include_substitutes'] ?? false),
        ];
    }

    /**
     * Great-circle distance (km) from a bound point (?, ?, ?) = (lat, lng, lat)
     * to the given latitude/longitude columns. LEAST/GREATEST guard ACOS against
     * floating-point drift outside [-1, 1] when the points coincide.
     */
    protected function haversineSql(string $latColumn, string $lngColumn): string
    {
        return "(6371 * ACOS(LEAST(1, GREATEST(-1, "
            . "COS(RADIANS(?)) * COS(RADIANS({$latColumn})) * COS(RADIANS({$lngColumn}) - RADIANS(?)) "
            . "+ SIN(RADIANS(?)) * SIN(RADIANS({$latColumn}))"
            . "))))";
    }
}
