<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\B2bOffer;
use App\Models\BuyerDemand;
use App\Models\Contract;
use App\Models\FreightPool;
use App\Models\FreightPoolMember;
use App\Models\HarvestListing;
use App\Models\User;
use App\Services\CoopPoolingService;
use App\Services\LogisticsPoolingService;
use App\Services\PriceIndexService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * GET /api/v1/dashboard/summary
     * Platform-level KPIs with 14-day micro-trends. When a Sanctum token is
     * present, offer and contract figures are scoped to that user.
     */
    public function summary(Request $request, CoopPoolingService $coopPools, PriceIndexService $prices, LogisticsPoolingService $logistics): JsonResponse
    {
        $user = $request->user('sanctum');
        $scoped = $user && ! $user->isAdmin();
        $today = Carbon::today();
        $from = $today->copy()->subDays(13);

        $offerScope = fn ($q) => $scoped
            ? $q->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
            : $q;

        $tradable = HarvestListing::query()->tradable();

        $listings = [
            'active' => (clone $tradable)->where('status', HarvestListing::STATUS_ACTIVE)->count(),
            'upcoming' => (clone $tradable)->where('status', HarvestListing::STATUS_UPCOMING)->count(),
            'available_volume_kg' => round((float) (clone $tradable)->sum('available_volume_kg'), 2),
            'market_value' => round((float) (clone $tradable)->sum(DB::raw('available_volume_kg * unit_price')), 2),
            'verified_pct' => (function () use ($tradable) {
                $total = (clone $tradable)->count();

                return $total ? round((clone $tradable)->where('is_verified', true)->count() / $total * 100, 1) : 0;
            })(),
        ];

        $pendingOffers = B2bOffer::where('status', B2bOffer::STATUS_PENDING)->tap($offerScope);
        $contracts = Contract::whereIn('status', Contract::LIVE_STATUSES)->tap($offerScope);

        $offers = [
            'pending' => (clone $pendingOffers)->count(),
            'pending_value' => round((float) (clone $pendingOffers)->sum('total_amount'), 2),
            'awaiting_you' => $user
                ? (clone $pendingOffers)->where('offered_by_id', '!=', $user->id)->count()
                : null,
        ];

        $contractStats = [
            'live' => (clone $contracts)->count(),
            'live_value' => round((float) (clone $contracts)->sum('total_value'), 2),
            'live_volume_kg' => round((float) (clone $contracts)->sum('agreed_volume_kg'), 2),
            'delivered_pct' => (function () use ($contracts) {
                $agreed = (float) (clone $contracts)->sum('agreed_volume_kg');

                return $agreed > 0 ? round((float) (clone $contracts)->sum('delivered_volume_kg') / $agreed * 100, 1) : 0;
            })(),
        ];

        $openDemand = [
            'count' => BuyerDemand::open()->count(),
            'volume_kg' => round((float) BuyerDemand::open()->sum('quantity_kg'), 2),
        ];

        return response()->json([
            'as_of' => now()->toIso8601String(),
            'scope' => $scoped ? 'user' : 'platform',
            'listings' => $listings,
            'offers' => $offers,
            'contracts' => $contractStats,
            'demand' => $openDemand,
            'coop_pools' => $coopPools->activePools($user?->role === User::ROLE_COOP_MANAGER ? $user->cooperativeId() : null, 4),
            // Buyers / inspectors don't ship produce: show platform-wide freight savings instead.
            'logistics' => $this->logisticsSummary($user, $scoped && ($user->canShip() || $user->hasRole(User::ROLE_DRIVER)), $logistics),
            'market_signals' => $prices->marketSignals(5),
            'trends' => [
                'listings_created' => $this->dailyCounts(HarvestListing::query(), $from, $today),
                'offers_created' => $this->dailyCounts(B2bOffer::query()->tap($offerScope), $from, $today),
                'contract_value' => $this->dailyCounts(Contract::query()->tap($offerScope), $from, $today, 'total_value'),
            ],
        ]);
    }

    /**
     * Shared-freight KPIs: open pools, farmer savings vs. solo trucking and
     * the current fuel surcharge position for the viewer's region.
     */
    private function logisticsSummary(?User $user, bool $scoped, LogisticsPoolingService $logistics): array
    {
        $members = FreightPoolMember::query()
            ->where('status', '!=', FreightPoolMember::STATUS_WITHDRAWN)
            ->when($scoped, fn ($q) => $q->where('user_id', $user->id));

        $pools = FreightPool::query()
            ->when($scoped, fn ($q) => $q->where(fn ($q) => $q->where('organizer_id', $user->id)->orWhere('driver_id', $user->id)
                ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id))));

        $solo = (float) (clone $members)->sum('solo_cost');
        $saved = (float) (clone $members)->sum('savings');
        $region = $user?->loadMissing('profile.province')->profile?->province?->region_code ?? config('agrimatch.fuel.fallback_region');
        $matrix = $logistics->fuelSurchargeMatrix($region);
        $diesel = $matrix['fuels']['diesel'];

        return [
            'open_pools' => (clone $pools)->open()->count(),
            'in_transit' => (clone $pools)->where('status', FreightPool::STATUS_IN_TRANSIT)->count(),
            'delivered_pools' => (clone $pools)->where('status', FreightPool::STATUS_DELIVERED)->count(),
            'farmers_pooled' => (clone $members)->distinct('user_id')->count('user_id'),
            'total_savings' => round($saved, 2),
            'savings_pct' => $solo > 0 ? round($saved / $solo * 100, 1) : 0,
            'pooled_volume_kg' => round((float) (clone $members)->sum('volume_kg'), 2),
            'fuel' => [
                'region_code' => $region,
                'diesel_price' => $diesel['price_per_liter'],
                'diesel_delta_pct' => $diesel['delta_pct'],
                'diesel_change' => $diesel['change_amount'],
                'effective_date' => $diesel['effective_date'],
                'surcharge' => $diesel['row'] !== null ? [
                    'short' => $matrix['rows'][$diesel['row']]['short'],
                    'medium' => $matrix['rows'][$diesel['row']]['medium'],
                    'long' => $matrix['rows'][$diesel['row']]['long'],
                ] : null,
            ],
        ];
    }

    /**
     * Zero-filled daily series between two dates (COUNT, or SUM of a column).
     */
    private function dailyCounts($query, Carbon $from, Carbon $to, ?string $sumColumn = null): array
    {
        $aggregate = $sumColumn ? "SUM({$sumColumn})" : 'COUNT(*)';

        $rows = $query
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->selectRaw("DATE(created_at) AS day, {$aggregate} AS total")
            ->groupBy('day')
            ->pluck('total', 'day');

        return collect(CarbonPeriod::create($from, $to))
            ->map(fn (\Carbon\CarbonInterface $d) => [
                'date' => $d->toDateString(),
                'value' => round((float) ($rows[$d->toDateString()] ?? 0), 2),
            ])
            ->values()
            ->all();
    }
}
