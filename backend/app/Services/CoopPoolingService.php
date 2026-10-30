<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAllocation;
use App\Models\Cooperative;
use App\Models\HarvestListing;
use App\Models\TradingHub;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cooperative Group Pooling Engine.
 *
 * A Coop Manager aggregates several smallholder lots of the same crop into one
 * "Parent Listing" big enough for institutional buyers (supermarkets, feed
 * mills, exporters). Buyers negotiate once, sign one contract, receive one
 * invoice; the engine then allocates the contracted volume back to member
 * farmers pro-rata to what each committed and computes their net payout after
 * the coop fee.
 *
 * Invariants (enforced under row locks):
 *  - every member lot belongs to a farmer of the same cooperative,
 *  - all lots are the same crop variety and still tradable,
 *  - a lot can sit in at most one open pool,
 *  - committed volume ≤ the lot's available volume, and is moved out of the
 *    member lot's availability so it can't be sold twice.
 */
class CoopPoolingService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Member lots a manager could pool for a crop.
     */
    public function candidates(Cooperative $coop, int $varietyId): Collection
    {
        return HarvestListing::query()
            ->tradable()
            ->with(['seller:id,name', 'seller.profile:id,user_id,business_name', 'tradingHub:id,short_name'])
            ->where('crop_variety_id', $varietyId)
            ->where('is_pool_parent', false)
            ->whereNull('parent_listing_id')
            ->whereIn('seller_id', $coop->memberProfiles()->select('user_id'))
            ->orderByDesc('available_volume_kg')
            ->get();
    }

    /**
     * @param  array{crop_variety_id: int, contributions: array<int, array{harvest_listing_id: int, volume_kg: float}>, unit_price?: ?float, title?: ?string, description?: ?string, minimum_order_kg?: ?float}  $data
     */
    public function createPool(User $manager, Cooperative $coop, array $data): HarvestListing
    {
        $contributions = collect($data['contributions'])->keyBy('harvest_listing_id');
        $cfg = config('agrimatch.coop_pool');

        if ($contributions->count() < $cfg['min_members']) {
            throw ValidationException::withMessages(['contributions' => "Pool at least {$cfg['min_members']} member lots."]);
        }

        return DB::transaction(function () use ($manager, $coop, $data, $contributions, $cfg) {
            /** @var Collection<int, HarvestListing> $lots */
            $lots = HarvestListing::query()
                ->with(['seller.profile', 'variety'])
                ->whereIn('id', $contributions->keys())
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $errors = [];
            foreach ($contributions as $listingId => $c) {
                $lot = $lots->get($listingId);
                $field = "contributions.{$listingId}";
                if (! $lot) {
                    $errors[$field] = "Listing #{$listingId} not found.";
                    continue;
                }
                if ($lot->seller?->profile?->cooperative_id !== $coop->id) {
                    $errors[$field] = "{$lot->reference_code} is not owned by a {$coop->name} member.";
                }
                if ((int) $lot->crop_variety_id !== (int) $data['crop_variety_id']) {
                    $errors[$field] = "{$lot->reference_code} is a different crop.";
                }
                if ($lot->is_pool_parent || $lot->parent_listing_id) {
                    $errors[$field] = "{$lot->reference_code} is already in a pool.";
                }
                if (! $lot->isTradable()) {
                    $errors[$field] = "{$lot->reference_code} is not open for sale.";
                }
                if ((float) $c['volume_kg'] <= 0 || (float) $c['volume_kg'] > $lot->available_volume_kg) {
                    $errors[$field] = sprintf('%s has only %s kg available.', $lot->reference_code, number_format($lot->available_volume_kg));
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $total = (float) $contributions->sum('volume_kg');
            $vwap = $contributions->sum(fn ($c) => $lots[$c['harvest_listing_id']]->unit_price * $c['volume_kg']) / $total;
            $worstGrade = $lots->pluck('quality_grade')->sortBy(fn ($g) => HarvestListing::GRADE_RANK[$g] ?? 9)->last();
            $certified = $lots->pluck('certified_grade');
            // A pool is only as good as its weakest certified lot — and uncertified if any lot is.
            $poolCertified = $certified->contains(null) ? null
                : $certified->sortBy(fn ($g) => HarvestListing::CERTIFIED_RANK[$g] ?? 9)->last();

            // Volume-weighted centroid of member pickups → truck staging point.
            $lat = $contributions->sum(fn ($c) => ($lots[$c['harvest_listing_id']]->pickup_latitude ?? 0) * $c['volume_kg']) / $total;
            $lng = $contributions->sum(fn ($c) => ($lots[$c['harvest_listing_id']]->pickup_longitude ?? 0) * $c['volume_kg']) / $total;
            $hasCoords = $lots->every(fn ($l) => $l->pickup_latitude !== null);

            $start = $lots->max(fn ($l) => $l->harvest_date_start);
            $end = $lots->max(fn ($l) => $l->harvest_date_end);
            $variety = $lots->first()->variety;

            $parent = new HarvestListing([
                'crop_variety_id' => $data['crop_variety_id'],
                'province_id' => $coop->province_id,
                'municipality' => $coop->municipality,
                'barangay' => $coop->barangay,
                'title' => $data['title'] ?? "{$variety->name} — Coop pooled lot from {$lots->count()} {$coop->municipality} smallholders",
                'description' => $data['description'] ?? sprintf(
                    'Parent listing aggregating %d member lots (%s kg) through %s. Single contract, single invoice; the cooperative handles consolidation, grading and pro-rata payout to member farmers.',
                    $lots->count(), number_format($total), $coop->name),
                'quality_grade' => $worstGrade,
                'is_organic' => $lots->every(fn ($l) => $l->is_organic),
                'stock_volume_kg' => $total,
                'available_volume_kg' => $total,
                'minimum_order_kg' => min($data['minimum_order_kg'] ?? $cfg['default_minimum_order_kg'], $total),
                'unit_price' => round(($data['unit_price'] ?? $vwap) * 4) / 4,
                'price_negotiable' => true,
                'harvest_date_start' => $start,
                'harvest_date_end' => $end,
                'status' => HarvestListing::statusForHarvestStart($start),
                'pickup_latitude' => $hasCoords ? round($lat, 7) : null,
                'pickup_longitude' => $hasCoords ? round($lng, 7) : null,
            ]);
            $parent->forceFill([
                'reference_code' => HarvestListing::nextReferenceCode(),
                'seller_id' => $manager->id,
                'cooperative_id' => $coop->id,
                'is_pool_parent' => true,
                'is_verified' => true,
                'certified_grade' => $poolCertified,
                'inspection_status' => $poolCertified ? 'passed' : 'not_requested',
                'trading_hub_id' => $hasCoords ? TradingHub::nearestTo($lat, $lng)?->id : $lots->first()->trading_hub_id,
            ])->save();

            foreach ($contributions as $listingId => $c) {
                $lot = $lots[$listingId];
                $remaining = round($lot->available_volume_kg - $c['volume_kg'], 2);
                $lot->forceFill([
                    'parent_listing_id' => $parent->id,
                    'pooled_volume_kg' => (float) $c['volume_kg'],
                    'available_volume_kg' => $remaining,
                    'status' => $remaining <= 0 ? HarvestListing::STATUS_POOLED : $lot->status,
                ])->save();
            }

            $this->audit->record('coop_pool.created', $parent, [
                'reference_code' => $parent->reference_code,
                'cooperative_id' => $coop->id,
                'stock_volume_kg' => $total,
                'unit_price' => $parent->unit_price,
                'members' => $contributions->map(fn ($c) => ['listing_id' => (int) $c['harvest_listing_id'], 'volume_kg' => (float) $c['volume_kg']])->values()->all(),
            ], actor: $manager);

            foreach ($lots as $lot) {
                $this->notifications->joinedCoopPool($lot->fresh(['seller.profile', 'variety']), $parent);
            }

            return $parent->fresh(['poolMembers', 'cooperative', 'variety']);
        });
    }

    /**
     * Allocate a pooled contract's volume back to member lots (largest-remainder
     * rounding to 0.01 kg so the parts always sum exactly to the contract).
     * Idempotent: does nothing if allocations already exist.
     */
    public function allocateContract(Contract $contract): Collection
    {
        $parent = $contract->listing()->with(['poolMembers', 'cooperative'])->first();
        if (! $parent?->is_pool_parent || $contract->allocations()->exists()) {
            return $contract->allocations;
        }

        $members = $parent->poolMembers->filter(fn ($m) => $m->pooled_volume_kg > 0)->values();
        $pooled = (float) $members->sum('pooled_volume_kg');
        if ($pooled <= 0) {
            return collect();
        }

        $target = (int) round($contract->agreed_volume_kg * 100);
        $raw = $members->map(fn ($m) => $target * $m->pooled_volume_kg / $pooled);
        $floors = $raw->map(fn ($v) => (int) floor($v));
        $remainder = $target - $floors->sum();
        $order = $raw->map(fn ($v, $i) => [$i, $v - floor($v)])->sortByDesc(1)->pluck(0)->take($remainder);
        $cents = $floors->map(fn ($f, $i) => $f + ($order->contains($i) ? 1 : 0));

        $feePct = (float) ($parent->cooperative?->coop_fee_pct ?? 0);

        $allocations = $members->map(function (HarvestListing $m, int $i) use ($contract, $cents, $feePct) {
            $kg = $cents[$i] / 100;
            $gross = round($kg * $contract->agreed_unit_price, 2);

            return ContractAllocation::create([
                'contract_id' => $contract->id,
                'harvest_listing_id' => $m->id,
                'farmer_id' => $m->seller_id,
                'allocated_volume_kg' => $kg,
                'unit_price' => $contract->agreed_unit_price,
                'gross_amount' => $gross,
                'coop_fee_pct' => $feePct,
                'net_payout' => round($gross * (1 - $feePct / 100), 2),
                'status' => 'pending',
            ]);
        });

        $contract->forceFill(['cooperative_id' => $parent->cooperative_id])->save();

        $this->audit->record('coop_pool.allocated', $contract, [
            'contract_number' => $contract->contract_number,
            'allocations' => $allocations->map(fn ($a) => ['farmer_id' => $a->farmer_id, 'kg' => $a->allocated_volume_kg, 'net' => $a->net_payout])->all(),
        ]);

        $this->notifications->poolAllocations($contract->fresh());

        return $allocations;
    }

    /**
     * Dissolve a pool: unsold volume returns to member lots pro-rata.
     */
    public function dissolve(HarvestListing $parent, User $actor): HarvestListing
    {
        if (! $parent->is_pool_parent) {
            throw ValidationException::withMessages(['listing' => 'Not a cooperative pool.']);
        }
        if ($parent->pendingOffers()->exists() || $parent->contracts()->whereIn('status', [Contract::STATUS_DRAFT, ...Contract::LIVE_STATUSES])->exists()) {
            throw ValidationException::withMessages(['listing' => 'Resolve pending offers and open contracts before dissolving the pool.']);
        }

        return DB::transaction(function () use ($parent, $actor) {
            $parent = HarvestListing::whereKey($parent->id)->lockForUpdate()->firstOrFail();
            $ratio = $parent->stock_volume_kg > 0 ? $parent->available_volume_kg / $parent->stock_volume_kg : 0;
            $returned = [];

            foreach ($parent->poolMembers()->lockForUpdate()->get() as $member) {
                $back = round($member->pooled_volume_kg * $ratio, 2);
                $available = min($member->stock_volume_kg, $member->available_volume_kg + $back);
                $member->forceFill([
                    'parent_listing_id' => $back >= $member->pooled_volume_kg ? null : $member->parent_listing_id,
                    'available_volume_kg' => $available,
                    'status' => $available > 0 ? HarvestListing::statusForHarvestStart($member->harvest_date_start) : HarvestListing::STATUS_SOLD_OUT,
                ])->save();
                $returned[] = ['listing_id' => $member->id, 'returned_kg' => $back];
            }

            $old = ['status' => $parent->status, 'available_volume_kg' => $parent->available_volume_kg];
            $parent->forceFill(['status' => HarvestListing::STATUS_EXPIRED, 'available_volume_kg' => 0])->save();

            $this->audit->record('coop_pool.dissolved', $parent, ['returned' => $returned], $old, $actor);

            return $parent;
        });
    }

    /** Dashboard summary of open pools, optionally scoped to one cooperative. */
    public function activePools(?int $cooperativeId = null, int $limit = 5): array
    {
        $query = HarvestListing::query()
            ->where('is_pool_parent', true)
            ->whereIn('status', HarvestListing::TRADABLE_STATUSES)
            ->when($cooperativeId, fn ($q) => $q->where('cooperative_id', $cooperativeId));

        $pools = (clone $query)
            ->with(['cooperative:id,name,municipality', 'variety:id,name'])
            ->withCount(['poolMembers', 'pendingOffers'])
            ->orderByDesc('stock_volume_kg')
            ->limit($limit)
            ->get();

        return [
            'count' => (clone $query)->count(),
            'pooled_volume_kg' => round((float) (clone $query)->sum('stock_volume_kg'), 2),
            'available_volume_kg' => round((float) (clone $query)->sum('available_volume_kg'), 2),
            'member_lots' => HarvestListing::whereIn('parent_listing_id', (clone $query)->select('id'))->count(),
            'items' => $pools->map(fn (HarvestListing $p) => [
                'id' => $p->id,
                'reference_code' => $p->reference_code,
                'crop' => $p->variety?->name,
                'cooperative' => $p->cooperative?->name,
                'municipality' => $p->cooperative?->municipality,
                'members' => $p->pool_members_count,
                'stock_volume_kg' => $p->stock_volume_kg,
                'available_volume_kg' => $p->available_volume_kg,
                'sold_pct' => $p->stock_volume_kg > 0 ? round((1 - $p->available_volume_kg / $p->stock_volume_kg) * 100, 1) : 0,
                'unit_price' => $p->unit_price,
                'certified_grade' => $p->certified_grade,
                'pending_offers' => $p->pending_offers_count,
                'quality_grade' => $p->quality_grade,
                'inspection_status' => $p->inspection_status,
                'harvest_date_start' => $p->harvest_date_start?->toDateString(),
                'harvest_date_end' => $p->harvest_date_end?->toDateString(),
                'status' => $p->status,
            ])->values(),
        ];
    }
}
