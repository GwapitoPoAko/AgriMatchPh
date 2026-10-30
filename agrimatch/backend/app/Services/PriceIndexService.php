<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\CropVariety;
use App\Models\HarvestListing;
use App\Models\HubPriceIndex;
use App\Models\OfficialPriceFeed;
use App\Models\TradingHub;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\NotificationService;

/**
 * Hub Price Index — daily weighted-average crop prices per regional trading
 * hub (Agora CDO, Valencia Bagsakan, La Trinidad, Divisoria …).
 *
 * Two evidence streams are blended:
 *
 *  1. Verified B2B contracts on this platform (fully signed, not cancelled /
 *     disputed). Contracts are sparse, so each one informs a rolling
 *     `contract_window_days` window. Farm-gate contracts are normalised to a
 *     hub-delivered basis by adding freight (₱/kg-km × road km to the hub).
 *  2. Official price feeds (DA Bantay Presyo, DA-AMAS, PSA, LGU surveys).
 *     Weekend gaps carry the most recent feed forward at reduced weight.
 *
 *   weighted_avg = Σ(price × volume × trust) / Σ(volume × trust)
 *
 * with trust = contract_trust (1.0) for contracts and feed_trust (0.6) for
 * feeds — platform deals are hard evidence, reported arrivals are estimates.
 *
 * The 7-day change drives listing warnings: ≥ +10% "market spike",
 * ≥ +4% "rising", ≤ −4% "softening", ≤ −10% "price drop".
 */
class PriceIndexService
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    /**
     * Recompute and upsert the index for one date. Optionally restrict to a hub or crop.
     *
     * @return int rows written
     */
    public function computeForDate(Carbon|string $date, ?int $hubId = null, ?int $varietyId = null): int
    {
        $date = Carbon::parse($date)->startOfDay();
        $cfg = config('agrimatch.price_index');
        $carryDays = (int) $cfg['feed_carry_forward_days'];
        $windowDays = (int) $cfg['contract_window_days'];

        $feeds = OfficialPriceFeed::query()
            ->whereBetween('report_date', [$date->copy()->subDays($carryDays)->toDateString(), $date->toDateString()])
            ->when($hubId, fn ($q) => $q->where('trading_hub_id', $hubId))
            ->when($varietyId, fn ($q) => $q->where('crop_variety_id', $varietyId))
            ->get(['trading_hub_id', 'crop_variety_id', 'report_date', 'price_avg', 'reported_volume_kg'])
            ->groupBy(fn ($f) => "{$f->trading_hub_id}:{$f->crop_variety_id}");

        $contracts = $this->contractEvidence($date->copy()->subDays($windowDays - 1), $date->copy()->endOfDay(), $hubId, $varietyId)
            ->groupBy(fn ($c) => "{$c['hub_id']}:{$c['variety_id']}");

        $keys = $feeds->keys()->merge($contracts->keys())->unique();
        if ($keys->isEmpty()) {
            return 0;
        }

        // Previous points for 1-day and 7-day change, fetched in one query.
        $history = HubPriceIndex::query()
            ->whereIn('price_date', [$date->copy()->subDay()->toDateString(), $date->copy()->subDays(7)->toDateString()])
            ->when($hubId, fn ($q) => $q->where('trading_hub_id', $hubId))
            ->when($varietyId, fn ($q) => $q->where('crop_variety_id', $varietyId))
            ->get(['trading_hub_id', 'crop_variety_id', 'price_date', 'weighted_avg_price'])
            ->keyBy(fn ($r) => "{$r->trading_hub_id}:{$r->crop_variety_id}:{$r->price_date->toDateString()}");

        $rows = [];
        $now = now();

        foreach ($keys as $key) {
            [$hub, $variety] = array_map('intval', explode(':', $key));

            // Feeds: same-day reports at full weight, otherwise the latest carried forward.
            $feedObs = [];
            $group = $feeds->get($key, collect());
            $sameDay = $group->filter(fn ($f) => $f->report_date->isSameDay($date));
            $useFeeds = $sameDay->isNotEmpty() ? $sameDay : $group->where('report_date', $group->max('report_date'));
            $feedWeight = $sameDay->isNotEmpty() ? 1.0 : (float) $cfg['feed_carry_forward_weight'];
            foreach ($useFeeds as $f) {
                $feedObs[] = ['price' => $f->price_avg, 'volume' => $f->reported_volume_kg * $feedWeight];
            }

            $contractObs = $contracts->get($key, collect())->all();

            $num = 0.0;
            $den = 0.0;
            foreach ($feedObs as $o) {
                $num += $o['price'] * $o['volume'] * $cfg['feed_trust'];
                $den += $o['volume'] * $cfg['feed_trust'];
            }
            foreach ($contractObs as $o) {
                $num += $o['price'] * $o['volume'] * $cfg['contract_trust'];
                $den += $o['volume'] * $cfg['contract_trust'];
            }
            if ($den <= 0) {
                continue;
            }

            $weighted = round($num / $den, 2);
            $feedVol = array_sum(array_column($feedObs, 'volume'));
            $contractVol = array_sum(array_column($contractObs, 'volume'));
            $allPrices = array_merge(array_column($feedObs, 'price'), array_column($contractObs, 'price'));

            $prev1 = $history->get("{$hub}:{$variety}:" . $date->copy()->subDay()->toDateString())?->weighted_avg_price;
            $prev7 = $history->get("{$hub}:{$variety}:" . $date->copy()->subDays(7)->toDateString())?->weighted_avg_price;
            $change7 = $prev7 ? round(($weighted / $prev7 - 1) * 100, 2) : null;

            $rows[] = [
                'trading_hub_id' => $hub,
                'crop_variety_id' => $variety,
                'price_date' => $date->toDateString(),
                'weighted_avg_price' => $weighted,
                'feed_avg_price' => $feedVol > 0 ? round(array_sum(array_map(fn ($o) => $o['price'] * $o['volume'], $feedObs)) / $feedVol, 2) : null,
                'contract_vwap' => $contractVol > 0 ? round(array_sum(array_map(fn ($o) => $o['price'] * $o['volume'], $contractObs)) / $contractVol, 2) : null,
                'min_price' => round(min($allPrices) * 0.95, 2),
                'max_price' => round(max($allPrices) * 1.05, 2),
                'feed_volume_kg' => round($feedVol, 2),
                'contract_volume_kg' => round($contractVol, 2),
                'feed_count' => count($feedObs),
                'contract_count' => count($contractObs),
                'change_1d_pct' => $prev1 ? round(($weighted / $prev1 - 1) * 100, 2) : null,
                'change_7d_pct' => $change7,
                'signal' => $this->classify($change7),
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows) {
            HubPriceIndex::upsert($rows, ['trading_hub_id', 'crop_variety_id', 'price_date'], [
                'weighted_avg_price', 'feed_avg_price', 'contract_vwap', 'min_price', 'max_price', 'feed_volume_kg',
                'contract_volume_kg', 'feed_count', 'contract_count', 'change_1d_pct', 'change_7d_pct', 'signal',
                'computed_at', 'updated_at',
            ]);
            Cache::forget('price-signals:latest-date');
            foreach ($rows as $row) {
                if ($row['change_7d_pct'] !== null && $row['change_7d_pct'] > (float) config('agrimatch.price_index.signals.spike', 15)) {
                    $this->alertPriceSpike($row);
                }
            }
        }

        return count($rows);
    }

    /** Recompute a date range in chronological order (7-day changes depend on earlier days). */
    public function recomputeRange(Carbon|string $from, Carbon|string $to, ?int $hubId = null, ?int $varietyId = null): int
    {
        $written = 0;
        foreach (CarbonPeriod::create(Carbon::parse($from), Carbon::parse($to)) as $day) {
            $written += $this->computeForDate($day, $hubId, $varietyId);
        }

        return $written;
    }

    /* ------------------------------------------------------------------ */
    /* Market signals (listing warnings)                                   */
    /* ------------------------------------------------------------------ */

    public function classify(?float $change7d): string
    {
        if ($change7d === null) {
            return 'stable';
        }
        $t = config('agrimatch.price_index.signals');

        return match (true) {
            $change7d >= $t['spike'] => 'spike',
            $change7d >= $t['rising'] => 'rising',
            $change7d <= $t['drop'] => 'drop',
            $change7d <= $t['falling'] => 'falling',
            default => 'stable',
        };
    }

    /** "+12% market spike", "−11% price drop" … null when stable. */
    public static function label(string $signal, ?float $pct): ?string
    {
        if ($pct === null || $signal === 'stable') {
            return null;
        }
        $n = (int) round(abs($pct));
        $sign = $pct >= 0 ? '+' : '−';

        return match ($signal) {
            'spike' => "{$sign}{$n}% market spike",
            'rising' => "{$sign}{$n}% prices rising",
            'falling' => "{$sign}{$n}% prices softening",
            'drop' => "{$sign}{$n}% price drop",
            default => null,
        };
    }

    public function latestDate(): ?string
    {
        return Cache::remember('price-signals:latest-date', 300, fn () => HubPriceIndex::max('price_date'));
    }

    /**
     * Latest hub signal for each listing's (hub, crop), keyed by listing id.
     * One query regardless of page size.
     *
     * @param  Collection<int, HarvestListing>  $listings
     * @return array<int, array<string, mixed>>
     */
    public function signalsForListings(Collection $listings): array
    {
        $latest = $this->latestDate();
        $pairs = $listings->filter(fn ($l) => $l->trading_hub_id)->map(fn ($l) => [$l->trading_hub_id, $l->crop_variety_id]);
        if (! $latest || $pairs->isEmpty()) {
            return [];
        }

        $rows = HubPriceIndex::query()
            ->with('hub:id,short_name')
            ->where('price_date', $latest)
            ->whereIn('trading_hub_id', $pairs->pluck(0)->unique())
            ->whereIn('crop_variety_id', $pairs->pluck(1)->unique())
            ->get()
            ->keyBy(fn ($r) => "{$r->trading_hub_id}:{$r->crop_variety_id}");

        $out = [];
        foreach ($listings as $l) {
            $row = $rows->get("{$l->trading_hub_id}:{$l->crop_variety_id}");
            if ($row) {
                $out[$l->id] = $this->present($row, $l->unit_price);
            }
        }

        return $out;
    }

    public function signalFor(int $hubId, int $varietyId, ?float $askingPrice = null): ?array
    {
        $row = HubPriceIndex::with('hub:id,short_name')
            ->where('trading_hub_id', $hubId)
            ->where('crop_variety_id', $varietyId)
            ->orderByDesc('price_date')
            ->first();

        return $row ? $this->present($row, $askingPrice) : null;
    }

    /** Biggest current movers across all hubs (dashboard + notifications). */
    public function marketSignals(int $limit = 8, bool $onlyAlerts = true): Collection
    {
        $latest = $this->latestDate();
        if (! $latest) {
            return collect();
        }

        return HubPriceIndex::query()
            ->with(['hub:id,short_name,code', 'variety:id,name,local_name'])
            ->where('price_date', $latest)
            ->when($onlyAlerts, fn ($q) => $q->where('signal', '!=', 'stable'))
            ->orderByRaw('ABS(change_7d_pct) DESC')
            ->limit($limit)
            ->get()
            ->map(fn (HubPriceIndex $r) => $this->present($r) + [
                'crop_variety_id' => $r->crop_variety_id,
                'variety' => $r->variety?->name,
                'local_name' => $r->variety?->local_name,
            ]);
    }

    /**
     * Chart.js-ready hub trend for one crop: shared ISO-date labels and one
     * dataset per hub, plus the contract/feed components for the tooltip.
     */
    public function hubTrends(int $varietyId, array $hubIds = [], int $days = 30): array
    {
        $latest = HubPriceIndex::where('crop_variety_id', $varietyId)->max('price_date');
        if (! $latest) {
            return ['labels' => [], 'datasets' => [], 'meta' => ['message' => 'No hub index for this crop yet.']];
        }
        $to = Carbon::parse($latest);
        $from = $to->copy()->subDays($days - 1);

        $rows = HubPriceIndex::query()
            ->where('crop_variety_id', $varietyId)
            ->when($hubIds, fn ($q) => $q->whereIn('trading_hub_id', $hubIds))
            ->whereBetween('price_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('price_date')
            ->get()
            ->groupBy('trading_hub_id');

        $labels = collect(CarbonPeriod::create($from, $to))->map(fn (\Carbon\CarbonInterface $d) => $d->toDateString())->values();
        $hubs = TradingHub::whereIn('id', $rows->keys())->get(['id', 'short_name', 'code'])->keyBy('id');

        $datasets = $rows->map(function (Collection $series, $hubId) use ($labels, $hubs) {
            $byDate = $series->keyBy(fn ($r) => $r->price_date->toDateString());
            $pick = fn (string $field) => $labels->map(fn ($d) => isset($byDate[$d]) ? $byDate[$d]->{$field} : null)->values();
            $last = $series->last();

            return [
                'id' => (int) $hubId,
                'label' => $hubs[$hubId]->short_name ?? "Hub {$hubId}",
                'data' => $pick('weighted_avg_price'),
                'feed' => $pick('feed_avg_price'),
                'contract' => $pick('contract_vwap'),
                'low' => $pick('min_price'),
                'high' => $pick('max_price'),
                'signal' => $this->present($last),
            ];
        })->values();

        return [
            'meta' => [
                'crop_variety_id' => $varietyId,
                'variety' => CropVariety::whereKey($varietyId)->value('name'),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'method' => 'Trust-weighted VWAP: verified contracts ×' . config('agrimatch.price_index.contract_trust')
                    . ', official feeds ×' . config('agrimatch.price_index.feed_trust'),
                'currency' => 'PHP',
                'unit' => 'kg',
            ],
            'labels' => $labels,
            'datasets' => $datasets,
        ];
    }

    /* ------------------------------------------------------------------ */

    private function present(HubPriceIndex $row, ?float $askingPrice = null): array
    {
        return [
            'hub_id' => $row->trading_hub_id,
            'hub' => $row->hub?->short_name,
            'as_of' => $row->price_date->toDateString(),
            'index_price' => $row->weighted_avg_price,
            'change_7d_pct' => $row->change_7d_pct,
            'signal' => $row->signal,
            'label' => self::label($row->signal, $row->change_7d_pct),
            'vs_index_pct' => $askingPrice && $row->weighted_avg_price > 0
                ? round(($askingPrice / $row->weighted_avg_price - 1) * 100, 1)
                : null,
            'evidence' => ['contracts' => $row->contract_count, 'feeds' => $row->feed_count],
        ];
    }

    /** Send one deduplicated platform alert for each hub/crop/date spike. */
    private function alertPriceSpike(array $row): void
    {
        $key = "price-spike-alert:{$row['trading_hub_id']}:{$row['crop_variety_id']}:{$row['price_date']}";
        if (! Cache::add($key, true, now()->addDay())) {
            return;
        }

        $variety = CropVariety::find($row['crop_variety_id']);
        $hub = TradingHub::find($row['trading_hub_id']);
        if (! $variety || ! $hub) {
            return;
        }
        $body = sprintf('%s at %s is up %.1f%% over seven days (DA price-feed and verified-contract index).', $variety->name, $hub->short_name, $row['change_7d_pct']);

        User::query()->where('status', 'active')
            ->whereIn('role', [User::ROLE_BUYER, User::ROLE_FARMER, User::ROLE_COOP_MANAGER, User::ROLE_TRADER])
            ->orderBy('id')->chunkById(200, function ($users) use ($variety, $hub, $body, $row) {
                foreach ($users as $user) {
                    $this->notifications->notify(
                        $user,
                        'price.spike',
                        "Price spike: {$variety->name} at {$hub->short_name}",
                        $body,
                        ['crop_variety_id' => $row['crop_variety_id'], 'trading_hub_id' => $row['trading_hub_id'], 'change_7d_pct' => $row['change_7d_pct']],
                        '/prices',
                        'high',
                        false,
                    );
                }
            });
    }

    /**
     * Verified contract observations in a window, normalised to hub-delivered ₱/kg.
     *
     * @return Collection<int, array{hub_id: int, variety_id: int, price: float, volume: float}>
     */
    private function contractEvidence(Carbon $from, Carbon $to, ?int $hubId, ?int $varietyId): Collection
    {
        $freightRate = (float) config('agrimatch.price_index.freight_per_kg_km');

        return Contract::query()
            ->join('harvest_listings as l', 'l.id', '=', 'contracts.harvest_listing_id')
            ->join('trading_hubs as h', 'h.id', '=', 'l.trading_hub_id')
            ->whereIn('contracts.status', Contract::PRICE_EVIDENCE_STATUSES)
            ->whereNotNull('contracts.signed_by_buyer_at')
            ->whereNotNull('contracts.signed_by_seller_at')
            ->whereBetween('contracts.signed_by_seller_at', [$from, $to])
            ->when($hubId, fn ($q) => $q->where('l.trading_hub_id', $hubId))
            ->when($varietyId, fn ($q) => $q->where('l.crop_variety_id', $varietyId))
            ->get([
                'contracts.agreed_unit_price', 'contracts.agreed_volume_kg', 'contracts.delivery_terms',
                'l.trading_hub_id', 'l.crop_variety_id', 'l.pickup_latitude', 'l.pickup_longitude',
                DB::raw('h.latitude AS hub_lat'), DB::raw('h.longitude AS hub_lng'),
            ])
            ->map(function ($c) use ($freightRate) {
                $price = (float) $c->agreed_unit_price;
                if ($c->delivery_terms === 'farm_gate' && $c->pickup_latitude !== null) {
                    $km = Geo::roadKm((float) $c->pickup_latitude, (float) $c->pickup_longitude, (float) $c->hub_lat, (float) $c->hub_lng);
                    $price += $freightRate * $km;
                }

                return [
                    'hub_id' => (int) $c->trading_hub_id,
                    'variety_id' => (int) $c->crop_variety_id,
                    'price' => round($price, 4),
                    'volume' => (float) $c->agreed_volume_kg,
                ];
            });
    }
}
