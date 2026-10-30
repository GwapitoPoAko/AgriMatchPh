<?php

namespace App\Http\Controllers\Api;

use App\Events\ContractSigned;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /** GET /api/v1/contracts?status= — contracts I'm a party to (or allocated to, as a coop member). */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'active', 'in_delivery', 'completed', 'disputed', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();

        $contracts = Contract::query()
            ->with(['listing:id,reference_code,title,crop_variety_id,is_pool_parent', 'listing.variety:id,name',
                'buyer:id,name', 'buyer.profile:id,user_id,business_name', 'seller:id,name', 'seller.profile:id,user_id,business_name',
                'cooperative:id,name'])
            ->withCount('allocations')
            ->when(! $user->isAdmin(), fn ($q) => $q->where(fn ($q) => $q
                ->where('buyer_id', $user->id)
                ->orWhere('seller_id', $user->id)
                ->orWhereHas('allocations', fn ($a) => $a->where('farmer_id', $user->id))))
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->latest()
            ->paginate($validated['per_page'] ?? 15);

        return response()->json($contracts);
    }

    /** GET /api/v1/contracts/{contract} */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        Gate::authorize('view', $contract);
        $user = $request->user();

        $contract->load(['listing.variety:id,name', 'offer:id,reference_code,message', 'buyer.profile', 'seller.profile', 'cooperative:id,name,coop_fee_pct']);

        // Parties see the whole payout table; a member farmer sees only their own row.
        $allocations = $contract->allocations()->with('farmer:id,name')
            ->when(! $user->isAdmin() && ! in_array($user->id, [$contract->buyer_id, $contract->seller_id], true),
                fn ($q) => $q->where('farmer_id', $user->id))
            ->get();

        return response()->json(['data' => $contract->toArray() + ['allocations' => $allocations, 'your_party' => $contract->partyRole($user)]]);
    }

    /** POST /api/v1/contracts/{contract}/sign — e-signature; activates when both parties signed. */
    public function sign(Request $request, Contract $contract): JsonResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        Gate::authorize('sign', $contract);
        $user = $request->user();

        $contract = DB::transaction(function () use ($contract, $user) {
            $contract = Contract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $party = $contract->partyRole($user);
            $old = ['status' => $contract->status];

            $contract->forceFill(["signed_by_{$party}_at" => now()]);
            $activated = $contract->signed_by_buyer_at && $contract->signed_by_seller_at;
            if ($activated) {
                $contract->forceFill(['status' => Contract::STATUS_ACTIVE]);
            }
            $contract->save();

            $this->audit->record('contract.signed', $contract, [
                'party' => $party,
                'contract_number' => $contract->contract_number,
                'total_value' => $contract->total_value,
                'status' => $contract->status,
            ], $old, $user);

            ContractSigned::dispatch($contract, $party, (bool) $activated);

            return $contract;
        });

        return response()->json(['data' => $contract->fresh()]);
    }
}
