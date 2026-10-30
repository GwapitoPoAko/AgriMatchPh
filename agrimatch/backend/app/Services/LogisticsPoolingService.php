<?php

namespace App\Services;

use App\Models\FreightPool;
use App\Models\FreightPoolMember;
use App\Models\FuelPriceLog;
use App\Models\HarvestListing;
use App\Models\TradingHub;
use App\Models\User;
use App\Support\Geo;
use App\Services\FuelPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Hakot" shared-freight calculator.
 *
 * Groups farmers in the same municipality shipping to the same trading hub
 * onto one truck and splits the cost fairly.
 *
 *  1. Route   — start at the pickup farthest from the hub, then nearest-
 *               neighbour through the remaining pickups, then to the hub.
 *  2. Truck   — smallest vehicle class whose capacity fits the pooled volume
 *               (multiple of the largest class above 14 T).
 *  3. Tariff  — trucks × (flag-down + ₱/km × route km × backhaul factor),
 *               quoted at the *baseline* fuel price.
 *  4. Fuel    — Dynamic Fuel Surcharge Matrix: the latest weekly DOE pump
 *               price for the hub's region vs baseline (row) × haul length
 *               band (column) → surcharge % on the tariff (negative = rebate).
 *  5. Split   — each farmer pays in proportion to volume × own distance to
 *               hub (ton-km), so a far, heavy pickup pays more than a near,
 *               light one.
 *  6. Savings — vs. each farmer hiring the smallest fitting truck alone.
 *
 * Open pools (forming / confirmed) are re-priced whenever a new fuel price
 * is logged, and members are told "+5% fuel surcharge applied".
 */
class LogisticsPoolingService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {
    }

    /* ------------------------------------------------------------------ */
    /* Quotes                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Price a set of pickups to one hub.
     *
     * @param  array<int, array{key: string, label: string, volume_kg: float, lat: float, lng: float, user_id?: ?int, listing_id?: ?int, barangay?: ?string, is_you?: bool}>  $participants
     */
    public function quote(TradingHub $hub, array $participants, Carbon|string|null $departure = null): array
    {
        if (! $participants) {
            throw ValidationException::withMessages(['participants' => 'Add at least one pickup to quote.']);
        }
        $departure = Carbon::parse($departure ?? Carbon::today());
        $region = $hub->regionCode();

        foreach ($participants as &$p) {
            $p['distance_to_hub_km'] = round(Geo::roadKm($p['lat'], $p['lng'], $hub->latitude, $hub->longitude), 2);
        }
        unset($p);

        [$stops, $routeKm] = $this->planRoute($participants, $hub);
        $totalKg = array_sum(array_column($participants, 'volume_kg'));
        [$vehicleKey, $trucks] = $this->pickVehicle($totalKg);
        $tariff = $this->tariff($vehicleKey, $trucks, $routeKm, $region, $departure);

        $weights = [];
        foreach ($participants as $p) {
            $weights[$p['key']] = $p['volume_kg'] * max($p['distance_to_hub_km'], 1.0);
        }
        $weightSum = array_sum($weights) ?: 1;

        $members = [];
        $allocated = 0.0;
        foreach (array_values($participants) as $i => $p) {
            $share = $i === count($participants) - 1
                ? round($tariff['total'] - $allocated, 2)
                : round($tariff['total'] * $weights[$p['key']] / $weightSum, 2);
            $allocated += $share;

            [$soloVehicle, $soloTrucks] = $this->pickVehicle($p['volume_kg']);
            $solo = $this->tariff($soloVehicle, $soloTrucks, $p['distance_to_hub_km'], $region, $departure);

            $members[] = [
                'key' => $p['key'],
                'label' => $p['label'],
                'user_id' => $p['user_id'] ?? null,
                'listing_id' => $p['listing_id'] ?? null,
                'barangay' => $p['barangay'] ?? null,
                'is_you' => (bool) ($p['is_you'] ?? false),
                'volume_kg' => round($p['volume_kg'], 2),
                'distance_to_hub_km' => $p['distance_to_hub_km'],
                'weight_pct' => round($weights[$p['key']] / $weightSum * 100, 1),
                'cost_share' => $share,
                'cost_per_kg' => $p['volume_kg'] > 0 ? round($share / $p['volume_kg'], 2) : null,
                'solo_vehicle' => $soloVehicle,
                'solo_cost' => $solo['total'],
                'savings' => round($solo['total'] - $share, 2),
                'savings_pct' => $solo['total'] > 0 ? round(($solo['total'] - $share) / $solo['total'] * 100, 1) : 0,
            ];
        }

        $soloTotal = array_sum(array_column($members, 'solo_cost'));
        $vehicle = config("agrimatch.logistics.vehicles.{$vehicleKey}");

        return [
            'hub' => ['id' => $hub->id, 'name' => $hub->name, 'short_name' => $hub->short_name, 'region_code' => $region,
                'lat' => $hub->latitude, 'lng' => $hub->longitude],
            'departure_date' => $departure->toDateString(),
            'vehicle' => [
                'class' => $vehicleKey,
                'label' => $vehicle['label'],
                'trucks' => $trucks,
                'capacity_kg' => $vehicle['capacity_kg'] * $trucks,
                'utilization_pct' => round($totalKg / ($vehicle['capacity_kg'] * $trucks) * 100, 1),
                'fuel_type' => $vehicle['fuel'],
                'est_fuel_liters' => round($routeKm * config('agrimatch.logistics.backhaul_factor') / $vehicle['km_per_liter'] * $trucks, 1),
            ],
            'route' => ['distance_km' => $routeKm, 'stops' => $stops],
            'total_volume_kg' => round($totalKg, 2),
            'cost' => $tariff,
            'members' => $members,
            'totals' => [
                'pooled_cost' => $tariff['total'],
                'solo_cost' => round($soloTotal, 2),
                'savings' => round($soloTotal - $tariff['total'], 2),
                'savings_pct' => $soloTotal > 0 ? round(($soloTotal - $tariff['total']) / $soloTotal * 100, 1) : 0,
                'cost_per_kg' => $totalKg > 0 ? round($tariff['total'] / $totalKg, 2) : null,
            ],
        ];
    }

    /**
     * Calculator entry point for one shipper: prices a solo trip and every
     * joinable pool (same hub, same municipality, departure within ±N days)
     * with the shipper added, best saving first. Also lists nearby farmers
     * with open lots heading to the same hub who could be invited.
     */
    public function quoteForShipper(TradingHub $hub, array $shipper, Carbon|string $departure, ?User $user = null): array
    {
        $departure = Carbon::parse($departure);
        $window = (int) config('agrimatch.logistics.pool_date_window_days');
        $radius = (float) config('agrimatch.logistics.max_pickup_radius_km');
        $you = $shipper + ['key' => 'you', 'label' => 'Your load', 'is_you' => true, 'user_id' => $user?->id];

        $solo = $this->quote($hub, [$you], $departure);

        $pools = FreightPool::query()
            ->open()
            ->with(['activeMembers.user.profile:id,user_id,business_name', 'activeMembers.listing.province:id,name', 'organizer:id,name'])
            ->where('trading_hub_id', $hub->id)
            ->where('is_solo', false)
            ->whereBetween('departure_date', [$departure->copy()->subDays($window)->toDateString(), $departure->copy()->addDays($window)->toDateString()])
            ->get()
            ->filter(fn (FreightPool $pool) => $pool->activeMembers->contains(
                fn (FreightPoolMember $m) => Geo::roadKm($m->pickup_latitude, $m->pickup_longitude, $you['lat'], $you['lng']) <= $radius
            ))
            ->reject(fn (FreightPool $pool) => $user && $pool->activeMembers->contains('user_id', $user->id));

        $options = $pools->map(function (FreightPool $pool) use ($hub, $you, $user) {
            $participants = $pool->activeMembers->map(fn (FreightPoolMember $m) => $this->participantFromMember($m, $user))->all();
            $participants[] = $you;
            $quote = $this->quote($hub, $participants, $pool->departure_date);
            $yours = collect($quote['members'])->firstWhere('is_you', true);

            return [
                'pool' => [
                    'id' => $pool->id,
                    'reference_code' => $pool->reference_code,
                    'status' => $pool->status,
                    'departure_date' => $pool->departure_date->toDateString(),
                    'organizer' => $pool->organizer?->name,
                    'origin' => $pool->origin_municipality,
                    'members' => $pool->activeMembers->count(),
                    'current_volume_kg' => $pool->total_volume_kg,
                ],
                'your_share' => $yours['cost_share'],
                'your_savings' => $yours['savings'],
                'your_savings_pct' => $yours['savings_pct'],
                'quote' => $quote,
            ];
        })->sortByDesc('your_savings')->values();

        $coLoaders = HarvestListing::query()
            ->tradable()
            ->with(['seller.profile:id,user_id,business_name', 'variety:id,name'])
            ->where('trading_hub_id', $hub->id)
            ->whereNotNull('pickup_latitude')
            ->when($user, fn ($q) => $q->where('seller_id', '!=', $user->id))
            ->whereNull('parent_listing_id')
            ->get()
            ->filter(fn ($l) => Geo::roadKm($l->pickup_latitude, $l->pickup_longitude, $you['lat'], $you['lng']) <= $radius)
            ->take(6)
            ->map(fn ($l) => [
                'listing_id' => $l->id,
                'seller' => $l->seller?->profile?->business_name ?? $l->seller?->name,
                'crop' => $l->variety?->name,
                'barangay' => $l->barangay,
                'municipality' => $l->municipality,
                'available_kg' => $l->available_volume_kg,
                'distance_from_you_km' => round(Geo::roadKm($l->pickup_latitude, $l->pickup_longitude, $you['lat'], $you['lng']), 1),
            ])->values();

        return [
            'solo' => $solo,
            'pool_options' => $options,
            'best_option' => $options->first(),
            'nearby_shippers' => $coLoaders,
            'fuel' => $this->fuelSurchargeMatrix($hub->regionCode(), $solo['cost']['distance_band']),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Pool lifecycle                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<int, array{harvest_listing_id: int, volume_kg: float}>  $loads
     */
    public function createPool(User $organizer, TradingHub $hub, Carbon|string $departure, array $loads, bool $solo = false): FreightPool
    {
        if ($solo && count($loads) !== 1) {
            throw ValidationException::withMessages(['loads' => 'A solo trip must contain exactly one harvest load.']);
        }

        return DB::transaction(function () use ($organizer, $hub, $departure, $loads, $solo) {
            $listings = HarvestListing::whereIn('id', array_column($loads, 'harvest_listing_id'))->lockForUpdate()->get()->keyBy('id');
            $first = $listings->first();

            $pool = new FreightPool([
                'trading_hub_id' => $hub->id,
                'origin_province_id' => $first->province_id,
                'origin_municipality' => $first->municipality,
                'departure_date' => Carbon::parse($departure)->toDateString(),
                'is_solo' => $solo,
            ]);
            $pool->forceFill([
                'reference_code' => FreightPool::nextReferenceCode(),
                'cooperative_id' => $first->cooperative_id,
                'organizer_id' => $organizer->id,
                'status' => FreightPool::STATUS_FORMING,
                'vehicle_class' => 'multicab',
                'capacity_kg' => 0,
            ])->save();

            foreach ($loads as $load) {
                $this->attachMember($pool, $listings[$load['harvest_listing_id']], (float) $load['volume_kg']);
            }

            $this->recalculate($pool, notify: false);
            $this->audit->record($solo ? 'freight_pool.solo_booked' : 'freight_pool.created', $pool, [
                'reference_code' => $pool->reference_code,
                'hub' => $hub->code,
                'members' => count($loads),
                'total_cost' => $pool->total_cost,
                'fuel_surcharge_pct' => $pool->fuel_surcharge_pct,
            ], actor: $organizer);

            return $pool->fresh(['activeMembers', 'hub']);
        });
    }

    public function join(FreightPool $pool, HarvestListing $listing, float $volumeKg, User $user): FreightPool
    {
        return DB::transaction(function () use ($pool, $listing, $volumeKg, $user) {
            $pool = FreightPool::whereKey($pool->id)->lockForUpdate()->firstOrFail();
            if (! $pool->isOpen()) {
                throw ValidationException::withMessages(['freight_pool_id' => 'This pool is no longer accepting loads.']);
            }
            if ($pool->is_solo) {
                throw ValidationException::withMessages(['freight_pool_id' => 'This truck was booked exclusively for a solo trip.']);
            }
            if ($pool->activeMembers()->where('harvest_listing_id', $listing->id)->exists()) {
                throw ValidationException::withMessages(['harvest_listing_id' => 'This lot is already on the truck.']);
            }

            $before = $pool->total_cost;
            $this->attachMember($pool, $listing, $volumeKg);
            $this->recalculate($pool, notify: false);

            $this->audit->record('freight_pool.joined', $pool, [
                'listing_id' => $listing->id, 'volume_kg' => $volumeKg, 'total_cost' => $pool->total_cost,
            ], ['total_cost' => $before], $user);

            $this->notifications->freightPoolUpdated(
                $pool,
                'freight.pool_joined',
                "New load joined {$pool->reference_code}",
                sprintf('%s kg added from Brgy. %s. Truck total now ₱%s — shares re-split.',
                    number_format($volumeKg), $listing->barangay, number_format($pool->total_cost, 0)),
            );

            return $pool->fresh(['activeMembers', 'hub']);
        });
    }

    public function transition(FreightPool $pool, string $status, User $actor, ?int $driverId = null): FreightPool
    {
        if (! $pool->canTransitionTo($status)) {
            throw ValidationException::withMessages(['status' => "Cannot move a {$pool->status} pool to {$status}."]);
        }

        return DB::transaction(function () use ($pool, $status, $actor, $driverId) {
            $old = ['status' => $pool->status, 'driver_id' => $pool->driver_id];
            $pool->forceFill(['status' => $status] + ($driverId ? ['driver_id' => $driverId] : []))->save();

            $memberStatus = match ($status) {
                FreightPool::STATUS_CONFIRMED => FreightPoolMember::STATUS_CONFIRMED,
                FreightPool::STATUS_IN_TRANSIT => FreightPoolMember::STATUS_PICKED_UP,
                FreightPool::STATUS_DELIVERED => FreightPoolMember::STATUS_DELIVERED,
                FreightPool::STATUS_CANCELLED => FreightPoolMember::STATUS_WITHDRAWN,
                default => null,
            };
            if ($memberStatus) {
                $pool->activeMembers()->update(['status' => $memberStatus, 'updated_at' => now()]);
            }

            $this->audit->record('freight_pool.' . $status, $pool, ['status' => $status, 'driver_id' => $pool->driver_id], $old, $actor);

            if ($status === FreightPool::STATUS_CONFIRMED) {
                $this->notifications->freightPoolUpdated($pool, 'freight.pool_confirmed', "Truck confirmed for {$pool->departure_date->format('M j')}",
                    sprintf('%s to %s. Your share is locked unless fuel prices change.', config("agrimatch.logistics.vehicles.{$pool->vehicle_class}.label"), $pool->hub?->short_name));
            }

            return $pool->fresh(['activeMembers', 'hub']);
        });
    }

    /**
     * Re-price a pool from its current members and the fuel price in force.
     * Persists route, truck, tariff, surcharge and every member's share.
     */
    public function recalculate(FreightPool $pool, bool $notify = true): FreightPool
    {
        $pool->loadMissing('hub.province');
        $members = $pool->activeMembers()->with(['user.profile:id,user_id,business_name', 'listing.province:id,name'])->get();
        if ($members->isEmpty()) {
            return $pool;
        }

        $quote = $this->quote($pool->hub, $members->map(fn ($m) => $this->participantFromMember($m))->all(), $pool->departure_date);
        $oldPct = (float) $pool->fuel_surcharge_pct;

        $pool->forceFill([
            'vehicle_class' => $quote['vehicle']['class'],
            'truck_count' => $quote['vehicle']['trucks'],
            'capacity_kg' => $quote['vehicle']['capacity_kg'],
            'total_volume_kg' => $quote['total_volume_kg'],
            'route_distance_km' => $quote['route']['distance_km'],
            'base_cost' => $quote['cost']['base'],
            'fuel_price_log_id' => $quote['cost']['fuel']['log_id'],
            'fuel_surcharge_pct' => $quote['cost']['surcharge_pct'],
            'fuel_surcharge_amount' => $quote['cost']['surcharge_amount'],
            'total_cost' => $quote['cost']['total'],
            'route_json' => ['stops' => $quote['route']['stops']],
            'last_recalculated_at' => now(),
        ])->save();

        $byKey = collect($quote['members'])->keyBy('key');
        foreach ($members as $m) {
            $q = $byKey->get("m{$m->id}");
            $m->forceFill([
                'distance_to_hub_km' => $q['distance_to_hub_km'],
                'cost_share' => $q['cost_share'],
                'solo_cost' => $q['solo_cost'],
                'savings' => $q['savings'],
            ])->save();
        }

        if ($notify && abs($oldPct - $pool->fuel_surcharge_pct) >= 0.01) {
            $sign = $pool->fuel_surcharge_pct >= 0 ? '+' : '−';
            $this->notifications->freightPoolUpdated(
                $pool,
                'freight.surcharge_changed',
                sprintf('%s%s%% fuel surcharge applied', $sign, rtrim(rtrim(number_format(abs($pool->fuel_surcharge_pct), 2), '0'), '.')),
                sprintf('%s re-priced to ₱%s after the DOE %s update (₱%s/L).', $pool->reference_code, number_format($pool->total_cost, 0),
                    $quote['cost']['fuel']['type'], number_format($quote['cost']['fuel']['price_per_liter'], 2)),
            );
        }

        return $pool;
    }

    /**
     * Re-price every open pool (optionally in one region). Called after a
     * fuel price is logged and nightly by the scheduler.
     *
     * @return array{repriced: int, surcharge_changed: int}
     */
    public function recalculateOpenPools(?string $regionCode = null): array
    {
        $repriced = 0;
        $changed = 0;

        FreightPool::query()
            ->open()
            ->with('hub.province')
            ->when($regionCode, fn ($q) => $q->whereHas('hub.province', fn ($p) => $p->where('region_code', $regionCode)))
            ->chunkById(100, function ($pools) use (&$repriced, &$changed) {
                foreach ($pools as $pool) {
                    $before = (float) $pool->fuel_surcharge_pct;
                    $this->recalculate($pool);
                    $repriced++;
                    if (abs($before - (float) $pool->fuel_surcharge_pct) >= 0.01) {
                        $changed++;
                    }
                }
            });

        return ['repriced' => $repriced, 'surcharge_changed' => $changed];
    }

    /* ------------------------------------------------------------------ */
    /* Fuel surcharge matrix                                               */
    /* ------------------------------------------------------------------ */

    /**
     * The full matrix with the current diesel/gasoline position highlighted.
     */
    public function fuelSurchargeMatrix(string $regionCode, ?string $activeBand = null, Carbon|string|null $on = null): array
    {
        $cfg = config('agrimatch.fuel');
        $fuels = [];
        foreach (FuelPriceLog::FUEL_TYPES as $type) {
            $log = FuelPriceLog::inForce($regionCode, $type, $on);
            $delta = $log?->deltaVsBaselinePct();
            $fuels[$type] = [
                'region_code' => $log?->region_code ?? $regionCode,
                'price_per_liter' => $log?->price_per_liter,
                'baseline' => FuelPolicy::baseline($type),
                'delta_pct' => $delta,
                'effective_date' => $log?->effective_date?->toDateString(),
                'change_amount' => $log?->change_amount,
                'row' => $delta === null ? null : $this->matrixRowIndex($delta),
            ];
        }

        return [
            'region_code' => $regionCode,
            'columns' => collect($cfg['distance_bands'])->map(fn ($b, $k) => ['key' => $k, 'label' => $b['label']])->values(),
            'rows' => collect($cfg['surcharge_matrix'])->map(fn ($r) => [
                'label' => $r['label'], 'short' => $r['short'], 'medium' => $r['medium'], 'long' => $r['long'],
            ])->values(),
            'fuels' => $fuels,
            'active_band' => $activeBand,
            'surcharge_multiplier' => FuelPolicy::surchargeMultiplier(),
        ];
    }

    public function surchargePct(float $deltaPct, float $distanceKm): array
    {
        $band = $this->distanceBand($distanceKm);
        $row = config('agrimatch.fuel.surcharge_matrix')[$this->matrixRowIndex($deltaPct)];

        return [(float) $row[$band], $band, $row['label']];
    }

    /* ------------------------------------------------------------------ */
    /* Internals                                                           */
    /* ------------------------------------------------------------------ */

    /** @return array{0: string, 1: int} [vehicle class, truck count] */
    public function pickVehicle(float $kg): array
    {
        $vehicles = config('agrimatch.logistics.vehicles');
        foreach ($vehicles as $key => $v) {
            if ($v['capacity_kg'] >= $kg) {
                return [$key, 1];
            }
        }
        $largest = array_key_last($vehicles);

        return [$largest, (int) ceil($kg / $vehicles[$largest]['capacity_kg'])];
    }

    /**
     * Base tariff at baseline fuel + dynamic surcharge from the matrix.
     */
    public function tariff(string $vehicleKey, int $trucks, float $km, string $regionCode, Carbon $on): array
    {
        $v = config("agrimatch.logistics.vehicles.{$vehicleKey}");
        $base = round($trucks * ($v['flag_down'] + $v['per_km'] * $km * config('agrimatch.logistics.backhaul_factor')), 2);

        $log = FuelPriceLog::inForce($regionCode, $v['fuel'], $on);
        $delta = $log?->deltaVsBaselinePct() ?? 0.0;
        [$pct, $band, $rowLabel] = $this->surchargePct($delta, $km);
        $pct = round($pct * FuelPolicy::surchargeMultiplier(), 2);
        $amount = round($base * $pct / 100, 2);

        return [
            'base' => $base,
            'surcharge_pct' => $pct,
            'surcharge_amount' => $amount,
            'total' => round($base + $amount, 2),
            'distance_band' => $band,
            'surcharge_label' => $pct == 0.0 ? null : sprintf('%s%s%% fuel %s applied', $pct > 0 ? '+' : '−',
                rtrim(rtrim(number_format(abs($pct), 2), '0'), '.'), $pct > 0 ? 'surcharge' : 'rebate'),
            'matrix_row' => $rowLabel,
            'fuel' => [
                'log_id' => $log?->id,
                'type' => $v['fuel'],
                'region_code' => $log?->region_code ?? $regionCode,
                'price_per_liter' => $log?->price_per_liter,
                'baseline' => FuelPolicy::baseline($v['fuel']),
                'delta_pct' => $delta,
                'effective_date' => $log?->effective_date?->toDateString(),
            ],
        ];
    }

    private function distanceBand(float $km): string
    {
        foreach (config('agrimatch.fuel.distance_bands') as $key => $band) {
            if ($band['max_km'] === null || $km < $band['max_km'] || ($key === 'medium' && $km <= $band['max_km'])) {
                return $key;
            }
        }

        return 'long';
    }

    private function matrixRowIndex(float $deltaPct): int
    {
        foreach (config('agrimatch.fuel.surcharge_matrix') as $i => $row) {
            $aboveMin = $row['min'] === null || $deltaPct > $row['min'];
            $belowMax = $row['max'] === null || $deltaPct <= $row['max'];
            if ($aboveMin && $belowMax) {
                return $i;
            }
        }

        return 1;
    }

    /**
     * @return array{0: array<int, array>, 1: float} [ordered stops, route km]
     */
    private function planRoute(array $participants, TradingHub $hub): array
    {
        $left = collect($participants)->sortByDesc('distance_to_hub_km')->values()->all();
        $route = [array_shift($left)];
        while ($left) {
            $cur = end($route);
            usort($left, fn ($a, $b) => Geo::roadKm($cur['lat'], $cur['lng'], $a['lat'], $a['lng']) <=> Geo::roadKm($cur['lat'], $cur['lng'], $b['lat'], $b['lng']));
            $route[] = array_shift($left);
        }

        $km = 0.0;
        $stops = [];
        foreach ($route as $i => $p) {
            $next = $route[$i + 1] ?? null;
            $leg = $next
                ? Geo::roadKm($p['lat'], $p['lng'], $next['lat'], $next['lng'])
                : Geo::roadKm($p['lat'], $p['lng'], $hub->latitude, $hub->longitude);
            $km += $leg;
            $stops[] = [
                'order' => $i + 1, 'type' => 'pickup', 'key' => $p['key'], 'label' => $p['label'],
                'barangay' => $p['barangay'] ?? null, 'lat' => $p['lat'], 'lng' => $p['lng'],
                'municipality' => $p['municipality'] ?? null, 'province' => $p['province'] ?? null,
                'volume_kg' => round($p['volume_kg'], 2), 'leg_km' => round($leg, 2), 'is_you' => (bool) ($p['is_you'] ?? false),
            ];
        }
        $stops[] = ['order' => count($route) + 1, 'type' => 'hub', 'key' => 'hub', 'label' => $hub->short_name,
            'barangay' => $hub->barangay, 'municipality' => $hub->municipality, 'province' => $hub->province?->name,
            'lat' => $hub->latitude, 'lng' => $hub->longitude, 'volume_kg' => null, 'leg_km' => 0, 'is_you' => false];

        return [$stops, round($km, 2)];
    }

    private function participantFromMember(FreightPoolMember $m, ?User $viewer = null): array
    {
        $name = $m->user?->profile?->business_name ?? $m->user?->name ?? 'Farmer';

        return [
            'key' => "m{$m->id}",
            // Other farmers' names are reduced to their barangay for privacy.
            'label' => $viewer && $viewer->id !== $m->user_id ? "Farmer in Brgy. {$m->pickup_barangay}" : $name,
            'user_id' => $viewer && $viewer->id !== $m->user_id ? null : $m->user_id,
            'listing_id' => $m->harvest_listing_id,
            'barangay' => $m->pickup_barangay,
            'municipality' => $m->listing?->municipality,
            'province' => $m->listing?->province?->name,
            'volume_kg' => $m->volume_kg,
            'lat' => $m->pickup_latitude,
            'lng' => $m->pickup_longitude,
            'is_you' => $viewer && $viewer->id === $m->user_id,
        ];
    }

    private function attachMember(FreightPool $pool, HarvestListing $listing, float $volumeKg): FreightPoolMember
    {
        if ($listing->pickup_latitude === null) {
            throw ValidationException::withMessages(['harvest_listing_id' => "{$listing->reference_code} has no pickup location set."]);
        }

        $member = new FreightPoolMember([
            'freight_pool_id' => $pool->id,
            'user_id' => $listing->seller_id,
            'harvest_listing_id' => $listing->id,
            'volume_kg' => $volumeKg,
            'pickup_barangay' => $listing->barangay,
            'pickup_latitude' => $listing->pickup_latitude,
            'pickup_longitude' => $listing->pickup_longitude,
        ]);
        $member->forceFill(['status' => FreightPoolMember::STATUS_REQUESTED])->save();

        return $member;
    }
}
