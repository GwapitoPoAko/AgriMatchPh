<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ContractPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if (! $user->isActive()) {
            return false;
        }

        // Admins may read everything but never sign on a party's behalf.
        return $user->isAdmin() && $ability !== 'sign' ? true : null;
    }

    public function view(User $user, Contract $contract): bool
    {
        if (in_array($user->id, [$contract->buyer_id, $contract->seller_id], true)) {
            return true;
        }

        // Member farmers can see contracts their pooled produce was allocated to.
        return $contract->allocations()->where('farmer_id', $user->id)->exists();
    }

    public function sign(User $user, Contract $contract): Response
    {
        $party = $contract->partyRole($user);
        if (! $party) {
            return Response::deny('Only the buyer or seller can sign this contract.');
        }
        if ($contract->status !== Contract::STATUS_DRAFT) {
            return Response::deny('This contract is no longer awaiting signatures.');
        }
        if ($contract->{"signed_by_{$party}_at"} !== null) {
            return Response::deny('You have already signed this contract.');
        }

        return Response::allow();
    }
}
