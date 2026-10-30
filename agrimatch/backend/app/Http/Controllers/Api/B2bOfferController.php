<?php

namespace App\Http\Controllers\Api;

use App\Events\OfferResponded;
use App\Events\OfferSubmitted;
use App\Http\Controllers\Controller;
use App\Http\Requests\RespondOfferRequest;
use App\Http\Requests\StoreOfferRequest;
use App\Models\B2bOffer;
use App\Models\Contract;
use App\Models\HarvestListing;
use App\Services\AuditLogger;
use App\Services\CoopPoolingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class B2bOfferController extends Controller
{
    private const OFFER_TTL_DAYS = 5;
    private const COUNTER_TTL_DAYS = 3;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly CoopPoolingService $coopPooling,
    ) {
    }

    /**
     * GET /api/v1/offers?box=sent|received&status=pending
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'box' => ['nullable', Rule::in(['sent', 'received', 'all'])],
            'status' => ['nullable', Rule::in(['pending', 'countered', 'accepted', 'rejected', 'withdrawn', 'expired'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $user = $request->user();
        $box = $validated['box'] ?? 'all';

        $offers = B2bOffer::query()
            ->with(['listing:id,reference_code,title,crop_variety_id,province_id,unit_price,is_pool_parent', 'listing.variety:id,name',
                'listing.province:id,name', 'buyer:id,name', 'buyer.profile:id,user_id,business_name',
                'seller:id,name', 'seller.profile:id,user_id,business_name', 'contract:id,b2b_offer_id,contract_number,status'])
            ->when(! $user->isAdmin(), fn ($q) => $q->where(function ($q) use ($user, $box) {
                if ($box === 'sent') {
                    $q->where('offered_by_id', $user->id);
                } elseif ($box === 'received') {
                    $q->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))
                        ->where('offered_by_id', '!=', $user->id);
                } else {
                    $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
                }
            }))
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($offers);
    }

    /**
     * POST /api/v1/offers — buyer opens a negotiation on a listing.
     */
    public function store(StoreOfferRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $offer = DB::transaction(function () use ($data, $user) {
            /** @var HarvestListing $listing */
            $listing = HarvestListing::whereKey($data['harvest_listing_id'])->lockForUpdate()->firstOrFail();

            // One live negotiation per buyer per listing keeps threads readable.
            $existing = B2bOffer::where('harvest_listing_id', $listing->id)
                ->where('buyer_id', $user->id)
                ->where('status', B2bOffer::STATUS_PENDING)
                ->exists();
            if ($existing) {
                throw ValidationException::withMessages([
                    'harvest_listing_id' => 'You already have a pending offer on this listing. Withdraw it or wait for a response.',
                ]);
            }

            $offer = B2bOffer::create([
                'reference_code' => B2bOffer::nextReferenceCode(),
                'harvest_listing_id' => $listing->id,
                'buyer_demand_id' => $data['buyer_demand_id'] ?? null,
                'buyer_id' => $user->id,
                'seller_id' => $listing->seller_id,
                'offered_by_id' => $user->id,
                'volume_kg' => $data['volume_kg'],
                'unit_price' => $data['unit_price'],
                'total_amount' => round($data['volume_kg'] * $data['unit_price'], 2),
                'delivery_terms' => $data['delivery_terms'],
                'delivery_date' => $data['delivery_date'] ?? null,
                'payment_terms' => $data['payment_terms'],
                'message' => $data['message'] ?? null,
                'status' => B2bOffer::STATUS_PENDING,
                'expires_at' => now()->addDays(self::OFFER_TTL_DAYS),
            ]);

            $this->audit->record('offer.created', $offer, [
                'reference_code' => $offer->reference_code,
                'listing_id' => $listing->id,
                'volume_kg' => $offer->volume_kg,
                'unit_price' => $offer->unit_price,
                'total_amount' => $offer->total_amount,
            ], actor: $user);

            OfferSubmitted::dispatch($offer);

            return $offer;
        });

        return response()->json(['data' => $offer->load('listing:id,reference_code,title')], 201);
    }

    /**
     * POST /api/v1/offers/{offer}/respond
     * action = accept | reject | counter | withdraw
     *
     * Accepting reserves volume on the listing and creates a DRAFT contract
     * carrying the acceptor's signature; the other party signs via
     * POST /contracts/{id}/sign to activate it. Pool contracts get their
     * member allocations immediately.
     */
    public function respond(RespondOfferRequest $request, B2bOffer $offer): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        $action = $validated['action'];

        $result = DB::transaction(function () use ($offer, $action, $validated, $user) {
            $offer = B2bOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if (! $offer->isOpen()) {
                throw ValidationException::withMessages(['action' => 'This offer is no longer open.']);
            }

            if ($action === 'withdraw') {
                $offer->update(['status' => B2bOffer::STATUS_WITHDRAWN, 'responded_at' => now()]);
                $this->audit->record('offer.withdrawn', $offer, ['status' => 'withdrawn'], ['status' => 'pending'], $user);

                return ['offer' => $offer->fresh()];
            }

            /** @var HarvestListing $listing */
            $listing = HarvestListing::whereKey($offer->harvest_listing_id)->lockForUpdate()->firstOrFail();

            if ($action === 'reject') {
                $offer->update(['status' => B2bOffer::STATUS_REJECTED, 'responded_at' => now()]);
                $this->audit->record('offer.rejected', $offer, ['status' => 'rejected'], ['status' => 'pending'], $user);
                OfferResponded::dispatch($offer, 'reject');

                return ['offer' => $offer->fresh()];
            }

            if ($action === 'counter') {
                $volume = (float) ($validated['volume_kg'] ?? $offer->volume_kg);
                if ($volume > $listing->available_volume_kg) {
                    throw ValidationException::withMessages(['volume_kg' => 'Counter volume exceeds available stock.']);
                }

                $offer->update(['status' => B2bOffer::STATUS_COUNTERED, 'responded_at' => now()]);

                $counter = B2bOffer::create([
                    'reference_code' => B2bOffer::nextReferenceCode(),
                    'harvest_listing_id' => $offer->harvest_listing_id,
                    'buyer_demand_id' => $offer->buyer_demand_id,
                    'buyer_id' => $offer->buyer_id,
                    'seller_id' => $offer->seller_id,
                    'parent_offer_id' => $offer->id,
                    'offered_by_id' => $user->id,
                    'volume_kg' => $volume,
                    'unit_price' => $validated['unit_price'],
                    'total_amount' => round($volume * $validated['unit_price'], 2),
                    'delivery_terms' => $offer->delivery_terms,
                    'delivery_date' => $offer->delivery_date,
                    'payment_terms' => $offer->payment_terms,
                    'message' => $validated['message'] ?? null,
                    'status' => B2bOffer::STATUS_PENDING,
                    'expires_at' => now()->addDays(self::COUNTER_TTL_DAYS),
                ]);

                $this->audit->record('offer.countered', $counter, [
                    'parent_offer' => $offer->reference_code, 'volume_kg' => $volume, 'unit_price' => (float) $validated['unit_price'],
                ], ['unit_price' => $offer->unit_price, 'volume_kg' => $offer->volume_kg], $user);
                OfferResponded::dispatch($offer, 'counter', $counter);

                return ['offer' => $offer->fresh(), 'counter_offer' => $counter];
            }

            // ---- accept -------------------------------------------------
            if (! $listing->isTradable() || $offer->volume_kg > $listing->available_volume_kg) {
                throw ValidationException::withMessages([
                    'volume_kg' => sprintf('Only %s kg remains on this listing.', number_format($listing->available_volume_kg)),
                ]);
            }

            $offer->update(['status' => B2bOffer::STATUS_ACCEPTED, 'responded_at' => now()]);

            $remaining = round($listing->available_volume_kg - $offer->volume_kg, 2);
            $listing->update([
                'available_volume_kg' => $remaining,
                'status' => $remaining <= 0 ? HarvestListing::STATUS_SOLD_OUT : $listing->status,
            ]);

            // Close sibling offers that can no longer be filled.
            B2bOffer::where('harvest_listing_id', $listing->id)
                ->where('id', '!=', $offer->id)
                ->where('status', B2bOffer::STATUS_PENDING)
                ->where('volume_kg', '>', $remaining)
                ->update(['status' => B2bOffer::STATUS_EXPIRED, 'responded_at' => now()]);

            $deliveryStart = $offer->delivery_date ?? max(now()->startOfDay(), $listing->harvest_date_start);

            $contract = new Contract([
                'contract_number' => Contract::nextContractNumber(),
                'b2b_offer_id' => $offer->id,
                'harvest_listing_id' => $listing->id,
                'buyer_id' => $offer->buyer_id,
                'seller_id' => $offer->seller_id,
                'agreed_volume_kg' => $offer->volume_kg,
                'agreed_unit_price' => $offer->unit_price,
                'total_value' => $offer->total_amount,
                'delivered_volume_kg' => 0,
                'delivery_terms' => $offer->delivery_terms,
                'payment_terms' => $offer->payment_terms,
                'delivery_start' => $deliveryStart,
                'delivery_end' => $deliveryStart->copy()->addDays(14),
            ]);
            // Accepting = the acceptor's signature. The other party signs next.
            $acceptorParty = $user->id === $offer->buyer_id ? 'buyer' : 'seller';
            $contract->forceFill([
                'status' => Contract::STATUS_DRAFT,
                "signed_by_{$acceptorParty}_at" => now(),
            ])->save();

            if ($offer->buyer_demand_id) {
                $offer->demand()->update(['status' => 'partially_matched']);
            }

            $this->audit->record('offer.accepted', $offer, [
                'contract_number' => $contract->contract_number,
                'volume_kg' => $offer->volume_kg,
                'unit_price' => $offer->unit_price,
                'listing_remaining_kg' => $remaining,
            ], ['status' => 'pending'], $user);
            $this->audit->record('contract.signed', $contract, [
                'party' => $acceptorParty, 'contract_number' => $contract->contract_number, 'total_value' => $contract->total_value,
            ], actor: $user);

            if ($listing->is_pool_parent) {
                $this->coopPooling->allocateContract($contract);
            }

            OfferResponded::dispatch($offer, 'accept');
            \App\Events\ContractSigned::dispatch($contract, $acceptorParty, false);

            return ['offer' => $offer->fresh(), 'contract' => $contract->fresh('allocations')];
        });

        return response()->json(['data' => $result]);
    }
}
